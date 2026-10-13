# Native Playwright Test startup: investigation and feasibility

> **Status:** investigation complete; architecture awaiting Andy's review. No runner implementation made.  
> **Owner:** Andy. **Last worker:** Codex. **Updated:** 2026-10-13.  
> **Workstream:** pest-e2e Step 32. This document also serves as its repository-local handoff.  
> **Governing rules:** ShowMyWork `.ai_handoff/AI_HANDOFF_RULES.md`. Read those rules before continuing; do not load or modify the completed finance handoff for this workstream.

## 1. Executive summary

**Persistent execution through Playwright's real native engine is technically possible and measurably faster. Neither existing persistent mechanism examined is a fully compatible replacement for independent `playwright test` invocations as it stands. Do not ship either prototype.**

Playwright 1.63.0's `test-server` accepts repeated external requests and runs native specs, fixtures, hooks and assertions. However, its interactive execution path overrides retries and repetition, among other CLI differences. A real WebSocket experiment confirmed that the same native spec/config passes through the CLI and fails through the server because these settings are overridden.

The internal `testRunner.runAllTestsWithConfig()` function is the actual CLI execution engine. Keeping its process alive saved approximately half the callback startup latency in a controlled benchmark. But it retains evaluated configuration and collected file suites. An unchanged native spec with an environment-derived title passed in two fresh CLI invocations and failed on its second run in a persistent host. That is a compatibility defect, not an acceptable restriction on specifications.

**Recommended first candidate for approval:** retain fresh native CLI processes and evaluate optional Node compile caching. It reuses compiled code while preserving fresh user-code evaluation. Median callback latency fell approximately **11% solo and 19% with six concurrent chains** in this experiment. It does not eliminate the entire startup tax. Disable it for precise V8 coverage and retain the current path where unsupported.

For a larger reduction, approve a bounded feasibility phase for a **fresh user-code isolation boundary around the actual runner**, preferably upstream-supported, or **single-use prewarmed CLI processes**. Neither is a completed design. Resetting environment variables or accepting stale configuration is insufficient. No specification migration, test API emulation or copied scheduler is proposed.

## 2. Scope and verified state

| Item | State at investigation start |
| --- | --- |
| ShowMyWork | `/home/andy/www/ShowMyWork`, `feature/payment-finance`, HEAD `a49d3e4` |
| App worktree | Intentionally modified `composer.json`, `composer.lock`; untracked `packages/`. Preserved. |
| pest-e2e | Separate repository `packages/pestE2E`, `fix/parallel-auth-ticket-lifetime`, HEAD `05e7970` |
| Auth fix | `d3a413d` is an ancestor; the local checkout includes it |
| Composer linkage | `vendor/valcuandrei/pest-e2e` resolves to the local package; Composer's path reference still identifies `d3a413d` |
| Installed JS | Node **24.16.0**, npm **11.17.0**; `@playwright/test`, `playwright`, `playwright-core` all **1.63.0** |
| Upstream source | Tag **v1.63.0**, resolved commit **`1b025d7e20a026371cd5f98ba0cdce48892737c8`** |
| CPU availability | 12, reported inside Sail |

Installed bundled JavaScript and corresponding upstream TypeScript were both inspected. The installed `playwright/lib/common/index.js` contains `cachedFileSuites`; `playwright/lib/runner/index.js` contains the same retry/repetition overrides and native runner entry discussed below. The package exports `playwright/lib/common` and `playwright/lib/runner`. Exportability is not a documented stable runner API guarantee.

The earlier report in commit `05e7970`, `docs/native-playwright-runner-investigation.md`, was read as prior work and independently checked. During this investigation it was externally renamed and then removed from the working tree. Preserve that external change; this report must be committed alone. Its compatibility concessions are not accepted under the current mission.

No application/package execution code, existing specifications, dependencies, schema or finance records were changed. New isolated experiments live under the ignored directory:

`ShowMyWork/storage/framework/testing/pw-native-investigation-astra/`

All Node commands used Sail. No application test database, real authentication exchange or provider was involved. The measured 4–8-second application problem is accepted; these experiments isolate components rather than try to re-prove it.

## 3. Verified execution lifecycle

### Before Playwright: the existing package

The current path is:

1. `src/PublicApi/E2ETargetHandle.php::actingAs()` records identity, guard, mode and metadata without issuing a ticket.
2. `E2ETargetHandle::run()` allocates a run ID and enters `ServerRunner::whenReady()` for the managed Laravel server.
3. `src/Runners/E2ERunner.php::run()` prepares report storage, passes admission and obtains the current Pest worker's warm-browser endpoint.
4. Its `lateParams` callback issues the ticket. `src/Builders/ProcessPlanBuilder.php` creates run-specific environment/params/options.
5. `src/Workers/Playwright/PlaywrightWorker.php::run()` starts the native executable and captures its result.
6. `PlaywrightParser` / `JsonReportReader` normalize the native JSON; the target handle revokes unexchanged tickets in `finally` and records Pest/agent diagnostics.

PHP/database setup, managed-server preparation, admission waits and launching the warm-browser service are before the requested startup boundary. JS globalSetup/auth exchange, worker browser connection and per-test context/page construction are inside it. Warm Chromium does not eliminate the native runner or its Node worker.

**The normal package path already avoids `npx`.** It resolves a local executable and runs `[localPath, ...args]` with Symfony Process; `runDlx()` is a fallback. Ordinary arguments are `test --workers=1`, optional literalized grep, headed/debug flags, per-run output, native JSON reporter and optional spec path. Direct Node invocation would remove almost nothing beyond the shebang path here. Do not replace consumer package resolution with a ShowMyWork-specific path.

### CLI to first callback

“I” means invocation; “W” native worker process; “F” loaded file; “T” test attempt, including retries. Some stages are lazy or omitted when fixtures are not requested. Source references identify exact-tag files/functions; all Playwright implementation APIs below are internal unless explicitly identified as public CLI/test APIs.

| Stage / implementation | Frequency and work | What may be reused, and what cannot be skipped |
| --- | --- | --- |
| 1. Optional npm entry: installed `npm/bin/npx-cli.js` → `lib/commands/exec.js::callExec()` → `libnpmexec` | I when npx is used: rewrite to npm exec, parse npm settings, resolve executable/package, invoke it | Local binary avoids this work; already implemented. No npm overhead is included in the local-CLI measurements. |
| 2. `@playwright/test/cli.js` → `require('playwright/lib/program')` → `program.parse(process.argv)` | I: initialize Node, load framework bundles, register Commander commands, parse args | Reuse compiled bytes, not evaluated user state. The CLI is supported; `program` internals are not a stable embedding interface. [Source][program] |
| 3. `cli/testActions.ts::runTests()` / `overridesFromOptions()` | I: construct all overrides/options, validate project filters, choose normal/UI/watch route; later map status to exit code | Preserve complete option mapping, including argv, profiling and mode behavior. A manually selected subset misses native functionality and future additions. [Source][actions] |
| 4. `configLoader.resolveConfigLocation()`, `loadConfigFromFile()`, `loadConfig()`; `FullConfigInternal` | I and W: discover/evaluate JS/TS/ESM config, validate, merge project/CLI settings, dependencies/teardowns, transform settings | Config can depend on run env. `deserializeConfig()` reloads it in workers. Cached controller config and fresh worker config can disagree. [Source][config-loader] |
| 5. `runAllTestsWithConfig()` → `createReporters()` / `LastRunReporter` | I: git/web-server plugin registration, reporter creation, last-failed information, task construction | Preserve native reporting, preprocessing, final status and failures. Fresh instances alone do not reset reporter module globals. [Runner][runner], [reporters][reporters] |
| 6. `tasks.createGlobalSetupTasks()` | I: output cleanup, plugin setup, global setup hooks and registered teardown | Hooks/configured web servers are consumer behavior, not removable overhead. Native webServer is separate from pest-e2e's managed Laravel server. [Source][tasks] |
| 7. `collectProjectsAndTestFiles()` → `projectUtils.collectFilesForProject()` | I/project: directory discovery and testMatch/testIgnore/gitignore filtering, then file filters; dependency files added | Selected spec paths reduce loading, **not necessarily directory traversal**. The package comment suggesting otherwise is too strong. Caching needs invalidation; dependencies must remain native. [Load][load-utils], [files][project-utils] |
| 8. `loadFileSuites()` → `InProcessLoaderHost` → `testLoader.loadTestFile()` | I/F: controller loads selected specs, detects duplicate titles/dependency problems, builds fixture pools | CLI uses an in-process loader. Process-global `cachedFileSuites` is not a safe independent-run cache. Server mode instead creates a new out-of-process loader. [Loader][loader-host], [suite cache][test-loader] |
| 9. `transform.requireOrImport()`, transform hooks, `compilationCache`, ESM loader | I/F and W/F: path/tsconfig resolution, transformation/source maps and module evaluation | Transformed source is already cached by content/path/version-sensitive keys. Cached source does not remove Node compilation/evaluation/registration. Disk layout and loader internals are not public contracts. [Transform][transform], [cache][compile-cache], [ESM][esm] |
| 10. `common/testType.ts::TestTypeImpl` | Every actual spec evaluation: native test/describe/hooks/modifiers/use/extend register suites | Registration is executable user code and may depend on current params. Must remain native; never replace it with exported callbacks or custom test APIs. [Source][test-type] |
| 11. `loadUtils.createRootSuite()` and task filters | I: project binding, grep/tags/locations/IDs, only, repetition, dependencies, reporter preprocessing and sharding filters | Preserve native ordering, errors and no-tests behavior. A previously filtered suite is not authoritative for another request. [Source][load-utils] |
| 12. `createPhasesTask()`, `createTestGroups()` / `filterForShard()`, `Dispatcher` | I/project/phase: dependency order, fixture hashes, project/repeat/file groups, serial/parallel/hook constraints, shard allocation and scheduling | Native scheduler remains in charge. Worker reuse within compatible groups of a run is different from reuse across independent requests. [Groups][groups], [dispatcher][dispatcher] |
| 13. `WorkerHost` → `ProcessHost.startRunner()` → `child_process.fork()` | W: child entry, env inheritance, IPC ready/init, serialized config, artifact dirs, worker/parallel indices | Fresh workers supply isolation; retries/failures can create more. `lastWorkerIndex` persists if controller persists. [Worker][worker-host], [process][process-host] |
| 14. `WorkerMain._loadIfNeeded()` / `runTestGroup()` | W/F: reload config/spec, project/repeat binding, match controller test IDs, build pools | Second evaluation is intentional: functions/fixtures execute in the worker. Stale controller IDs cause the reproduced failure. Skipping worker loading changes semantics. [Source][worker-main] |
| 15. `FixtureRunner.resolveParametersForFunction()` / fixture pools | Lazy W/T/hook: auto/requested fixture dependencies, scopes, use/setup/teardown, timeout/failure tracking | Must retain actual native dependency resolution. Carrying worker fixtures into another Pest run changes lifecycle. [Runner][fixtures], [registration][fixture-defs] |
| 16. Built-in fixtures `src/index.ts`; hooks in `WorkerMain._runTest()` | Browser launch/connect usually W; context/page normally T. Modifiers, beforeAll, beforeEach and requested/auto fixtures run | Warm endpoint works with native connectOptions. Preserve fresh contexts/storageState and trace/video/screenshot instrumentation. Hook failure/skip may prevent callback entirely. [Built-ins][native-fixtures], [hooks][worker-main] |
| 17. `WorkerMain._runTest()` → `testInfo._runWithTimeout()` → `await fn(testFunctionParams, testInfo)` | T: actual callback entry after preparation | This is the requested timing endpoint. `onTestBegin` is earlier. Native steps/assertions/timeouts/retries and all after-hooks/artifacts/reporting must still work afterward. [Source][worker-main] |

`createRunTestsTask()` calls `Dispatcher.stop()` after each phase and defensively in teardown. Repeated high-level runs do **not** retain these native workers. Controller persistence savings must not be described as worker reuse.

## 4. Measurements

### Controlled conditions and boundaries

The probe is an ordinary ESM spec importing native `test`/`expect`, with `test.extend()` for an automatic page-dependent fixture, beforeAll/beforeEach, data-URL navigation and a title assertion. Test timeout: 5 seconds. No existing spec or native API was patched.

Browser launch was outside timing. Solo variants shared one dedicated warm Chromium server; six-chain variants had six dedicated warm servers, one per chain, consistently across variants. Fresh context/page construction remained timed. All variants omitted Laravel/network auth equally. These are comparable **component experiments**, not full-application speedup measurements.

`t0` is the parent's monotonic timestamp just before spawning a CLI, or dispatching to a ready persistent host. JSONL markers use `process.hrtime.bigint()` on the same machine and record stage/PID. `callback` is the callback's first statement. Complete time ends at CLI exit or host response after teardown/reporting. A temporary Node preload plus native config/reporter/hooks exposes intermediate boundaries. Persistent-host initialization is separate.

Six runs per solo variant: exclude the first, report five. Three waves of six for concurrent variants: exclude the first wave, report twelve. Variants were sequential rather than randomized; small samples, marker I/O and custom reporter overhead limit inference. Naive hosts retain evaluated configuration, including the first outputDir, so their performance is **not evidence of correct artifact isolation**.

### Actual milliseconds

| Mechanism | Solo callback median [range], n=5 | Solo complete median | Six-chain callback median [range], n=12 | Six-chain complete median |
| --- | ---: | ---: | ---: | ---: |
| Fresh local native CLI | 1,593 [1,537–1,621] | 1,767 | 2,462 [2,363–2,520] | 2,845 |
| CLI + warm Node compile cache | 1,421 [1,383–1,465] | 1,624 | 2,004 [1,974–2,047] | 2,319 |
| Persistent `runAllTestsWithConfig` | 831 [795–879] | 963 | 1,112 [1,044–1,144] | 1,352 |
| Same host + compile cache | 665 [654–712] | 811 | 1,153 [997–1,285] | 1,387 |
| Persistent server `TestRunner`, direct method calls | 1,360 [1,345–1,387] | 1,495 | Not measured | Not measured |

The last row exercises the server's actual engine with explicit setup/teardown, **not end-to-end WebSocket transport**. The separate real protocol experiment below proves repeated requests and semantic differences. The six-chain internal host was slightly slower with compile cache: gains are not uniform or additive.

First solo callbacks: CLI 1,744 ms; CLI/cache 1,685; internal 1,082; internal/cache 716; server engine 1,399. One-time host readiness: internal 1,106 ms, internal/cache 565, server 664. These readiness figures are individual observations.

### One observed CLI timeline

Fourth solo CLI sample, not a synthetic sum of medians:

| Boundary | Milliseconds after spawn |
| --- | ---: |
| Controller Node preload | 35.7 |
| Enter user config | 716.9 |
| Reporter constructed | 721.8 |
| Global setup entered | 727.5 |
| Controller spec evaluation | 764.7 |
| Reporter onBegin / suite ready | 786.1 |
| Native worker Node preload | 836.7 |
| Worker user config | 1,364.9 |
| Worker spec evaluation | 1,405.0 |
| Reporter onTestBegin | 1,425.0 |
| beforeAll | 1,467.8 |
| Native page ready | 1,598.6 |
| beforeEach | 1,599.0 |
| Actual callback | 1,599.6 |

About 681 ms lies between controller preload and config entry; about 528 ms between worker preload and worker config entry. Those intervals include framework/module initialization and associated runtime work, **not CPU-only attribution to one bundle**. Config entry through suite-ready takes about 69 ms. The beforeAll-to-page-ready gap is about 131 ms. These measurements do not support blaming browser-process startup, TypeScript transformation or discovery alone for the full delay.

Playwright's transform cache already persists transformed source. Node's separate compile cache saves V8 compilation while retaining fresh module evaluation. Node documents first-load overhead, runtime/path constraints, exit-time flushing and reduced V8 coverage precision. [Node 24.16 source documentation][node-cache]

### Compatibility counterexamples

**A. Dynamic native registration.** The unchanged probe includes this pattern:

```js
import { test, expect } from '@playwright/test';
test(process.env.CASE_NAME || 'native probe', async ({ page }) => {
  await page.goto('data:text/html,<title>probe</title>');
  await expect(page).toHaveTitle('probe');
});
```

Fresh CLI with `CASE_NAME=A`, then B: **both pass**. Persistent host calls to `loadConfigFromFile()` + `runAllTestsWithConfig()`: **A passes; B fails before callback**, reporting “Test not found in the worker process. Make sure test title does not change.” The title is stable within each request. The controller retains A's suite while the fresh worker registers B. This is explained by `cachedFileSuites`, not by invalid spec behavior.

**B. Real WebSocket server.** A separate native spec asserts `expect(testInfo.retry).toBe(1)`; config has `retries:1`, `repeatEach:2`, one worker. Fresh CLI exits **0**, with `(repeat,retry)` callbacks `(0,0), (0,1), (1,0), (1,1)`: two flaky successes. The real command `node <local-cli> test-server -c <same-config> --host 127.0.0.1` accepts initialize/setup/run/teardown RPCs. Each of two requests returns **failed** with only `(0,0)`. Same spec/config, no patches. This proves repeated external execution and incompatible retry/repetition policy.

### Reproduction

From ShowMyWork, executed sequentially:

```text
vendor/bin/sail node storage/framework/testing/pw-native-investigation-astra/bench/run.cjs
vendor/bin/sail node storage/framework/testing/pw-native-investigation-astra/bench/server-contract.cjs
vendor/bin/sail node storage/framework/testing/pw-native-investigation-astra/bench/run-concurrent.cjs
```

Scripts, raw `results.json`, `concurrent-results.json`, JSONL markers and native retry reports remain in that ignored scratch directory. They are not production code. Reproduce with these versions, the probe/boundaries above, native `workers:1`, `timeout:5000`, `connectOptions:{wsEndpoint}` from `chromium.launchServer()`, and a marker reporter/setup. The central calls are:

```js
// Fresh: spawn(process.execPath,
//   [require.resolve('@playwright/test/cli'), 'test', '-c', configPath, specPath],
//   { env: completeRunEnvironment });

// Feasibility host ONLY: intentionally demonstrates why naive persistence fails.
const { configLoader } = require('playwright/lib/common');
const { testRunner } = require('playwright/lib/runner');
const config = await configLoader.loadConfigFromFile(configPath, { workers: 1 });
const status = await testRunner.runAllTestsWithConfig(config, { locations: [specPath] });
```

Repeat the internal calls in one process, changing CASE_NAME, to reproduce A. The server-engine probe constructs `new testRunner.TestRunner(resolveConfigLocation(configPath), {})`, initializes once, and calls global setup, runTests and teardown every time. The real protocol uses `{id,method,params}` JSON over the printed WebSocket endpoint. Cache variants set `NODE_COMPILE_CACHE` to a dedicated scratch path, inherited by native workers. No production config was changed.

## 5. Existing persistent facilities

### A. `test-server`

`program.ts::addTestServerCommand()` registers a **hidden** command. `runTestServer()` resolves the config location and starts a trace-viewer HTTP/WebSocket server. `TestServerDispatcher` owns a persistent `TestRunner`; `TestServerConnection` carries request IDs, responses/errors and reporter/stdout events. Supported requests include selected locations, IDs, projects and grep, listing, watching, cancellation and global setup/teardown. pest-e2e can communicate with it; the actual WebSocket experiment did. [Server][server], [interface][server-interface], [connection][server-connection]

UI mode shares `innerRunTestServer()`. Watch mode initializes a connection, performs setup/listing, watches changes and repeatedly requests selections. Official VS Code source starts `test-server`, initializes it, and invokes runTests with selected projects/locations/IDs. Its inspected commit is `95dfd7147b694215e1455596c6dd153077aff365`. This is real upstream usage, not a stable public protocol promise. [Watch][watch], [VS Code][vscode]

What persists:

- Controller/framework modules, watcher, serialized queue, plugin instances and optional global-setup lifecycle.
- Config loading is requested again, but Node may return cached evaluated config. This is not a correctly invalidated per-request config cache.
- Each run/list uses a fresh out-of-process loader; native run tasks stop test workers. No cross-request native worker reuse was found.
- Browser persistence is separate, through browser servers/connectWsEndpoint. Interactive context reuse is distinct and must not replace normal isolated contexts.

Concrete differences from ordinary CLI in 1.63.0:

- `_innerRunTests()` forces **repeatEach=1, retries=0**.
- It sets **passWithNoTests=true, preserveOutputDir=true**.
- Its `runTasks()` receives zero global timeout instead of the ordinary CLI global-timeout argument.
- Global setup/teardown are separate requests, not automatically repeated for each run.
- Dispatcher runTests sets `doNotRunDepsOutsideProjectFilter=true`, unlike the ordinary CLI dependency selection path.
- RunTestsParams is an interactive subset, not all CLI options. It has no general per-request env/cwd/config-location/output-dir contract. Setup's returned `env` describes produced changes; it is not an incoming environment setter.
- Streamed telemetry is not one complete native JSON report per request. Config reporters can run, but framing and artifact paths still need explicit management.

Sending an undocumented retries parameter cannot undo an override in the implementation. Adoption would need upstream changes or maintained internals, with explicit approval. Its stdin-close, signals and closeOnDisconnect behavior also need an owning PHP lifecycle; keep execution transport private/loopback, and clean up the process tree.

### B. UI/watch architecture

Reusable ideas: owned controller, serialized requests, cancellation, streamed native results, dependency watching and separate browser transport. Its interactive policy is unsuitable wholesale. Holding globalSetup-authenticated state across Pest requests can bind the wrong user. Context reuse/headed interaction/reduced retries are not correct defaults. File watching does not invalidate values captured from changing request environment.

### C. Native internal runner

`runAllTestsWithConfig` is usable and remains the actual engine. No **documented supported interface for fresh, independent repeated runs in one process** was found. CLI runTests additionally owns argument conversion/modes/profiling/env/exit behavior.

New FullConfigInternal objects do not reset config modules, CJS/ESM caches, collected suites, fixture/helper/reporting closures, setup side effects, process listeners or workerIndex. There is no inspected public “reset this runtime as if it were a fresh CLI” operation. `clearCache()` removes transformation cache files, not all this state.

Clearing require.cache misses ESM and the suite Map. Cache-busting root module URLs misses transitive modules and can duplicate Playwright instances. Reimplementing partial invalidation and registration semantics is the fragile compatibility layer this task forbids.

## 6. Candidate architectures

| Candidate | Native contract and expected savings | Feasibility / risk | Verdict |
| --- | --- | --- | --- |
| **1. Optimize existing CLI** | Strongest native equivalence: fresh processes and actual CLI. Local binary already bypasses npx. Compile cache saved ~11% solo/~19% six-chain here. | Preserve servers/admission/tickets/browser/DTO/parser/artifacts. Add only guarded supported-runtime cache propagation if approved; disable for precise coverage; test cold/unwritable caches and package managers. Lowest maintenance. | **Recommended first scoped approval.** No claim to eliminate 4–8 seconds. |
| **2. Existing test-server** | Real native engine, but interactive behavior differs. Direct engine saved ~15% solo; real transport performance unmeasured. | Fresh loader/workers remain. Need per-worker ownership, env/config lifecycle, telemetry framing, cancellation/artifacts/auth. Hidden protocol and upstream behavior changes. | **Reject unchanged as transparent replacement.** Needs CLI-equivalent upstream request semantics/isolation. |
| **3. Persistent host using internal runner** | Native engine, but cross-run state breaks equivalence. ~48% solo/~55% six-chain callback saving without compile cache, excluding initial host startup. | Needs complete fresh-user-code boundary, all CLI semantics, failure/exit handling, report framing and version qualification. Serial requests alone do not remove contamination. Highest maintenance. | **Reject naive implementation.** Larger gains justify bounded isolation research only if approved. |
| **4a. Single-use prewarmed native CLI** | Potential fresh-run equivalence because a process never executes two requests. Bootstrap inputs still require proof. | Moves work off critical path when it overlaps useful work; does not eliminate CPU/memory. Not independently measured here. Prewarm before user config/code, with known process inputs; admission-aware ownership and cleanup required. | **Research candidate**, not a demonstrated zero-difference solution. |
| **4b. Persistent supervisor, fresh isolate/child per request** | Full fresh child preserves process isolation; worker-thread/VM equivalence is not guaranteed for process APIs/native modules/custom loaders. | Fresh child still loads framework; essentially Option 1 unless safe compiled bytes are reused. Sharing evaluated framework across partial realms complicates ESM/singletons. No measured large gain. | Do not add a supervisor for unproven speed. An upstream clean runner realm is a research direction. |
| **4c. Batch independent Pest tests into one native run** | Specs can remain native, but PHP setup/DB state, params, auth, failure attribution and hook boundaries change. | Native worker reuse amortizes cost within an actual suite; consumers can already choose suite execution. Automatic batching changes the Pest request contract. | **Reject as a transparent optimization.** No spec or PHP workflow migration. |

Only-playwright browser automation, custom test/assertion APIs and copied schedulers are rejected outright. The test MCP entry is another interactive integration, not evidence of a hidden fresh-CLI reset interface.

### Isolation required before approving Option 3

| State | Required boundary |
| --- | --- |
| Config/projects/dependencies/filters | Evaluate current complete request inputs and native selection/error rules. Separate or retire runtimes on cwd/config/Node/Playwright changes. |
| Suite/module registration | Fresh user setup/config/spec/helper/reporter/fixture evaluation including transitive CJS and ESM. Correct file/env changes. Retain only safe compiled-code caches. |
| Env/argv/cwd | Full replacement, including removed keys, not Object.assign accumulation. Setup-produced changes live only inside a request. Startup-only Node flags cannot be applied retroactively. |
| Workers/fixtures/retries | Native engine chooses grouping/restarts/scopes. No carrying fixtures/workers across independent runs. Resolve worker index semantics rather than teaching consumers new assumptions. |
| Browser/auth | Existing per-Pest-worker browser process may persist; ordinary contexts/pages/storageState remain per run/test. |
| Reports/artifacts/IDs | Fresh instances and module state; complete per-run JSON/status/stdio, unique output/attachments/video/trace/snapshots, correct freshly collected IDs and last-run behavior. |
| Failures/cleanup | Cancel owned children, complete teardown where possible, no late results in another run; orphan cleanup on PHP death. Never silently replay ambiguously executed tests. |

## 7. Existing pest-e2e feature compatibility

| Feature | Integration requirements |
| --- | --- |
| Ordinary Pest Browser / native specs | Preserve target/public handle/JsWorkerContract; no redirects of unrelated Pest browser tooling or replacement JS APIs. Existing native specs stay unchanged. |
| Pest parallel / managed servers | Keep ParallelWorkerContext's DB/port/cache/TEST_TOKEN rules. Scope controllers to owning PHP invocation/process and target runtime, not a globally reusable numeric worker token. Leave managed-server lifecycle intact. |
| Admission | Gate expensive preparation and execution using existing admission. No hidden queue wait after ticket issuance; prewarming cannot bypass resource controls or spawn uncontrolled spares. |
| Warm browser | Keep WarmBrowserManager/endpoint propagation and native connectOptions. Normal contexts remain fresh. Preserve cold fallback before execution when preparation fails. Other browser projects must remain native. |
| actingAs / params / overrides | withEnv, inline/file params, APP_URL, run ID, guard/mode/meta are request-specific. Config may capture them at import. ShowMyWork actually captures storageState/outputDir using run ID: stale config is practical risk even with fixed test titles. |
| Selection/config | Preserve consumer discovery, TS/ESM, projects/dependencies, tags, grep/locations, hooks/retries and all existing override behavior. No changing configs to conceal runner limitations. |
| Reports / PAO | Current worker forces native JSON and parser expects one stdout JSON document. Persistent output requires request framing and final status; test-server telemetry cannot be passed directly to PlaywrightParser. Preserve errors/stacks/results and reporter extension behavior. |
| Artifacts | Keep native failure/retry capture policies and unique directories; do not enable UI context reuse or disable trace/video to improve numbers. Never clean another active run's artifacts. |
| Headed/debug | Preserve browse/debug, inspector, signals and process options. No timeout increases. Unsupported optimized modes use the unchanged CLI. |
| Cleanup/recovery | Dead controller must fail promptly and clean its tree. Fallback is safe before accepting work, or for the next request; **not** automatic replay after a connection breaks during possible execution. Tests can have side effects. |
| Other consumers | Support multiple targets/config roots, npm/yarn/pnpm/bun, local versions, Windows/Linux/macOS and custom runner bindings. ShowMyWork config is evidence, not a universal template. |

## 8. Authentication lifecycle

The new boundary would be **ready-to-execute admission to an isolated native run**, not daemon startup or enqueue:

```text
actingAs identity → managed server ready → admission granted
→ browser ready / existing cold fallback selected
→ correct runner healthy and exclusive request slot reserved
→ issue ticket and finalize params
→ immediately enter this run's native setup/auth exchange
→ tests and teardown → revoke unused ticket / retire auth material
```

Do not lose the reserved slot and queue the newly issued ticket. A crash before exchange revokes the old ticket; any explicitly restarted execution needs a new one. If execution may have begun, report uncertainty rather than replay side effects.

Preserve testing-only endpoint/header checks, expiry, worker-scoped store, intended user/guard binding, single-use exchange and finally revocation. Do not share authenticated request contexts, storageState files, browser contexts, cookies or Sanctum tokens across independent requests. Native retries inside one run can use that run's already-produced storageState without exchanging the ticket again.

Inspected: LaravelAuthTicketIssuer, CacheAuthTicketStore, AuthRequest, E2EAuthController, core.mjs and playwright.mjs. `readParams()` reads current input per call, but arbitrary consumer module values may not. `globalSetup()` writes `.pest-e2e/{runId}/storageState.json`; config may capture that path once. Refreshing only the helper is insufficient.

This source review and the no-auth benchmarks are **not a security certification of existing auth code**. Future HTTP acceptance must verify stored ticket identity/guard mismatch handling and replay rejection explicitly. Do not weaken or repair unrelated authentication behavior as an unreviewed performance shortcut.

## 9. Recommendation and proposed implementation phases

Keep native CLI as correctness oracle and fallback. Approve the modest, guarded compile-cache path first if useful, then decide whether remaining latency justifies an isolation project. Do not take the faster naive-host number as authorization to relax native semantics.

No phase below is implemented or automatically approved:

1. Agree supported runtimes/versions, latency target and CPU/memory budget. Full unchanged native behavior remains mandatory.
2. If approved, optional supported Node compile-cache propagation with coverage guards, cache lifecycle and unchanged fresh CLI. Measure real app specs under existing server/admission/browser/auth conditions.
3. If larger research is approved, prove single-use prewarm bootstrap semantics or an upstream CLI-equivalent isolated request interface. First test dynamic env/config/ESM/transitive modules, reports and hooks. Stop if correct isolation brings back essentially all overhead. Forks/internal modifications require explicit approval.
4. Only after proof, integrate behind existing worker contracts: ready/reserve/execute/complete/cancel lifecycle, owning-worker isolation, late ticket issuance, unique artifacts and safe process-tree failure handling. Unknown versions/modes keep using CLI.
5. Qualify native equivalence, package compatibility and measured complete-suite improvement across platforms/versions/load. Do not hide increased CPU or flaky failures behind lower callback latency.
6. Andy reviews rollout/default policy separately. No publishing/tagging in this workstream.

## 10. Risks and acceptance criteria

### Risks

Internal exports can change without stable contracts. Native CLI option/reporting/preprocess/worker functionality continues evolving. Partial cache invalidation cannot reliably isolate CJS, ESM, transitive helpers and native singleton identity. User setup/reporters/plugins can create timers/listeners/processes or call process.exit. Serializing requests avoids simultaneous env mutation but not previous-run state.

Stale storageState/output paths and ticket queuing threaten run identity. Telemetry, retries and final statuses must agree, and lost results cannot safely imply no execution. Tiny probes do not measure real app setup/network behavior. Compile caching has first-run/storage/runtime/coverage constraints. Fresh native worker initialization, fixtures and hooks remain real costs.

### Native equivalence

Use stock CLI as oracle on identical unchanged specs/configs. Compare selection/IDs/counts/statuses/retries, annotations/steps, hook/fixture logs, artifacts and errors; normalize only intrinsically variable timings/PIDs and approved output roots.

Cover:

- Native test/describe including serial/parallel, all hooks, skip/fixme/fail/slow, steps, use/extend, scoped/auto/dependent fixtures, setup/teardown failures, assertions and auto-retrying matchers.
- Retries/repeatEach, test/global timeouts, maxFailures/failOnFlakyTests, dependency/teardown projects, sharding, project workers, grep/tags/locations/IDs, only/forbidOnly/no-tests behavior.
- CJS/ESM/TS config/spec/helpers; changed and removed env/params, source changes, dynamic registration/project names, setup-generated env, multiple targets and startup flags.
- Custom/native reporters and module state, preprocessing, JSON/HTML/blob where applicable, collection/hook/fixture errors, stdout/stderr, annotations/attachments/snapshots/retry traces/videos/screenshots/source maps.
- New native features through actual installed engine. Unknown future versions must remain usable through CLI, never through partial emulation.

### Package/auth/recovery

Keep existing public API and unchanged E2E coverage, custom worker bindings and registration after container refresh. Test two concurrent Pest invocations with multiple workers: correct DB/URL/cache/auth/browser/artifact ownership and long repeated runs without leaks.

Delay admission/runner readiness beyond ticket TTL **before issuance**, then authenticate successfully. Reject expired/reused/mismatched identity/guard tickets; cancellation revokes unused tickets. No cross-user context reuse. Exercise death during collection/fixtures/tests/reporting, IPC disconnects, worker hangs, PHP death and browser loss; fail and clean up without duplicate execution or orphan accumulation. Preserve debug/headed/capture modes and cache-disabled/unwritable/cold behavior, including precise coverage.

### Performance

Use enough real runs for median/tail distributions, agreed warmup and randomized/interleaved variants. Same versions/specs/server readiness/admission/auth/artifacts/timeouts. Measure spawn-or-dispatch to **first callback**, whole Pest suite duration, CPU/RSS and process cleanup. Include six-worker and ordinary higher-contention loads, cold starts and failures. Andy sets the gain threshold; this report does not invent one or project subsecond performance onto every 4–8-second invocation.

## 11. Decisions for Andy

1. **Next authorized phase:** guarded CLI compile caching first (recommended), immediate bounded isolation/prewarm research, or retain current behavior if modest gains do not justify configuration.
2. **Success target:** callback median/tail and total-suite gain, plus memory/CPU budget under real conditions. Native functionality is not a tradeoff option.
3. **Internal API maintenance:** upstream-supported contribution versus version-gated internal adapter/fork; each needs explicit scope. Willingness to maintain internals never authorizes reduced behavior.
4. **Prewarm research:** whether latency overlap is worth investigating despite unchanged CPU work and spare-process/admission costs.
5. **Support/rollout:** Node/Playwright/platform/package-manager matrix, coverage/debug modes, opt-in/default policy and unsupported-version fallback.

## 12. Handoff, verification and stop point

Completed source tracing, integration/auth inspection, exact-tag review, component timing, real protocol check, two compatibility counterexamples and this report.

- Solo timing matrix: **30/30 runs passed**, five mechanisms × six runs.
- Six concurrent chains: **72/72 runs passed**, four mechanisms × eighteen runs.
- Dynamic registration: **2/2 fresh CLI passed**; persistent A passed and B intentionally reproduced one failure before callback.
- Retry/repetition: CLI exit **0**, four attempts across two repeated tests; both real server requests intentionally failed after one attempt with retry=0/repeat=0.
- All three experiment coordinator commands completed with exit **0**. This indicates experiment completion, not semantic success of the incompatible candidates.
- No production application/package suites run: no runtime code changed. No test database touched, existing spec edited or timeout increased.

Only this new report is intended for commit. Preserve external deletion/rename of the prior report and intentionally dirty app Composer files. Package inspected parent: `05e7970`; app HEAD: `a49d3e4`. Obtain this report's commit hash from Git history. Scratch experiments remain ignored and are not shipped.

**Exact next step:** Andy reviews Sections 1, 6, 9 and 11, then authorizes any implementation or further research. **Stop here** until that review. No daemon/default switch, spec migration, finance changes, dependency changes or release.

**Final answer:** some repeated work can be avoided while running fully native unchanged specs, concretely via compiled-code reuse with fresh CLI processes. Larger savings from a persistent native controller are measured, but both ready-made routes examined fail independent-run compatibility. A safe larger optimization needs proven fresh user-code isolation or a qualified single-use prewarm design. Eliminating the whole 4–8-second tax is **not established**; concrete barriers are stale evaluated config/suites and interactive server policies, while fresh worker/fixture/hook work remains necessary.

[pw-src]: https://github.com/microsoft/playwright/tree/v1.63.0/packages/playwright/src
[program]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/program.ts
[actions]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/cli/testActions.ts
[config-loader]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/common/configLoader.ts
[runner]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/testRunner.ts
[reporters]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/reporters.ts
[tasks]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/tasks.ts
[load-utils]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/loadUtils.ts
[project-utils]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/projectUtils.ts
[loader-host]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/loaderHost.ts
[test-loader]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/common/testLoader.ts
[transform]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/transform/transform.ts
[compile-cache]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/transform/compilationCache.ts
[esm]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/transform/esmLoader.ts
[test-type]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/common/testType.ts
[groups]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/testGroups.ts
[dispatcher]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/dispatcher.ts
[worker-host]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/workerHost.ts
[process-host]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/processHost.ts
[worker-main]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/worker/workerMain.ts
[fixtures]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/worker/fixtureRunner.ts
[fixture-defs]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/common/fixtures.ts
[native-fixtures]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/index.ts
[server]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/testServer.ts
[server-interface]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/isomorphic/testServerInterface.ts
[server-connection]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/isomorphic/testServerConnection.ts
[watch]: https://github.com/microsoft/playwright/blob/v1.63.0/packages/playwright/src/runner/watchMode.ts
[vscode]: https://github.com/microsoft/playwright-vscode/blob/95dfd7147b694215e1455596c6dd153077aff365/src/playwrightTestServer.ts
[node-cache]: https://github.com/nodejs/node/blob/v24.16.0/doc/api/module.md#module-compile-cache
