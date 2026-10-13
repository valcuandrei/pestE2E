# Native Playwright Test runner startup — investigation

> **Status:** investigation complete, awaiting Andy's architectural review. Nothing in the production execution path was changed.
> **Owner:** Andy
> **Worker:** Claude (Opus 5.5)
> **Date:** 2026-10-13
> **Package HEAD:** `d3a413d` on `fix/parallel-auth-ticket-lifetime`
> **Playwright studied:** `@playwright/test` / `playwright` / `playwright-core` **1.63.0** (installed in ShowMyWork), upstream tag `v1.63.0`
> **Runtime measured:** Node 24.16.0 inside the ShowMyWork Sail container, 12 logical CPUs (WSL2)

This package has no handoff system, so this document is the workstream record. It lives in `docs/`, which `.gitattributes` already marks `export-ignore`, so it does not ship to consumers.

Source references are written as `lib/<bundle>.js:<line>` (the installed, unminified 1.63.0 bundle under `node_modules/playwright/`) followed by the upstream file under `packages/playwright/src/` that the bundle's own section comments name.

---

## 1. Executive summary

**Can pest-e2e avoid repeatedly paying the startup tax while running unchanged `@playwright/test` specs through Playwright's own engine? Partly: about half of it, not all of it.**

- The tax is not browser startup, config loading, discovery or TypeScript transpilation. It is **two Node processes each loading and evaluating about 6.5 MB of Playwright JavaScript**: the runner process (about 730 ms on an idle machine) and the worker process it forks (about 530 ms). Everything else before the first test callback adds up to roughly 400 ms.
- That work is CPU-bound, so it stretches under parallel load. With 12 concurrent invocations the same run takes 3.8–4.9 s to reach the first callback, which matches the 4–8 s Andy measured.
- **The runner-process half can be removed natively.** A long-lived Node process per Pest worker that repeatedly calls the two functions the real CLI calls (`configLoader.loadConfigFromFile` and `testRunner.runAllTestsWithConfig`) cut time-to-first-callback by 42–49 % in every load condition measured. Collection, `globalSetup`, scheduling, the worker, fixtures, hooks, retries and reporters remain Playwright's own code.
- **The worker-process half cannot be removed natively.** Playwright stops every worker at the end of every run, including in its own persistent test server. Keeping a worker alive across runs would mean replacing Playwright's dispatcher, which is a rejected approach.
- Playwright's built-in `test-server` is **not suitable**: it saved only 13–15 %, forces `retries: 0`, and cannot take per-run environment or output directories.
- One change needs no architecture at all: setting `NODE_COMPILE_CACHE` for the Playwright child process measured 13–16 % faster with no behavioural change.

The persistent runner depends on two undocumented Playwright internals and has three small observable differences from the CLI (section 6). Whether that is acceptable is the main decision for Andy.

### Measured results (medians, milliseconds)

"Callback" is request to first line of the test callback. "Run" is request to runner finished. Chains are concurrent sequential streams, modelling Pest workers.

| Approach | 1 chain: callback / run | 6 chains: callback / run | 12 chains: callback / run |
|---|---|---|---|
| Today: fresh CLI per run | 1610 / 1868 | 2492 / 3082 | 3815 / 4837 |
| Fresh CLI + `NODE_COMPILE_CACHE` | 1350 / 1610 | not measured | 3231 / 4174 |
| Pre-warmed single-use CLI | 881 / 1150 | 1978 / 2568 | 3542 / 4679 |
| Playwright `test-server` | 1404 / 1625 | not measured | 3257–3525 / 4128 |
| **Persistent in-process runner** | **822 / 1063** | **1282 / 1782** | **2194 / 3124** |
| Persistent runner + `NODE_COMPILE_CACHE` | 830 / 1135 | not measured | 1893 / 2847 |

These are measurements of an isolated benchmark, not of the ShowMyWork suite. See section 3 for conditions and limits.

---

## 2. Verified execution lifecycle

How pest-e2e launches Playwright today: `PlaywrightWorker::run()` (`src/Workers/Playwright/PlaywrightWorker.php`) executes `node_modules/.bin/playwright test --workers=1 [--grep …] --output <dir> --reporter json [spec]` with Symfony Process. `.bin/playwright` is a symlink to `@playwright/test/cli.js`, so **`npx` is already not in the path**; there is nothing to gain from "direct Node entry point versus npx".

Frequency key: **I** = once per CLI invocation, **W** = once per worker process, **F** = once per spec file per process, **T** = once per test.

| # | Stage | What it does | Source (1.63.0) | Freq | Essential? | Internal/public | Skippable without changing behaviour? |
|---|---|---|---|---|---|---|---|
| 1 | Node start, CLI entry | `cli.js` is two lines: `require('playwright/lib/program')`, then `program.parse(process.argv)` | `@playwright/test/cli.js`, `playwright/cli.js` | I | Yes | `bin` is public; `lib/program` is an exported subpath | No, but it can be done ahead of time |
| 2 | Module loading (runner) | `program.js` requires `playwright-core` `utilsBundle` (3.0 MB), `coreBundle` (3.5 MB), `lib/common`, `lib/runner`, MCP and agent modules. **733 ms measured** | `lib/program.js:24-34` | I | The code is needed; re-loading it per run is not | — | **Yes, by keeping the process alive** |
| 3 | Command registration, argv parsing | commander registers `test`, `test-server`, etc.; `runTests(args, opts)`; `overridesFromOptions()` maps flags to config overrides | `lib/program.js:37-65`, `lib/cli/testActions.js:42,113` (`src/program.ts`, `src/cli/testActions.ts`) | I | Yes | Internal | Negligible cost |
| 4 | Config discovery and load | `resolveConfigLocation` → `loadConfig` → `requireOrImport(configFile)` → `new FullConfigInternal(...)` applies CLI overrides | `lib/common/index.js:1459,1273,1267,479` (`src/common/configLoader.ts`, `config.ts`) | I, and again W | Yes | Internal | No. About 15 ms. Note: the module is cached by Node, see section 6 |
| 5 | Transform setup | CJS hook and ESM loader for TS/JSX; Babel output cached on disk in `os.tmpdir()/playwright-transform-cache-<uid>` | `lib/common/index.js:79,1052,1097,1164` (`src/transform/`) | I and W | Yes | Internal | Already cached across invocations |
| 6 | Reporters | `createReporters()` instantiates configured/CLI reporters plus `LastRunReporter` | `lib/runner/index.js:5195,6938` (`src/runner/reporters.ts`) | I | Yes | Reporter API is public | No. Negligible |
| 7 | Plugins and global setup | `createGlobalSetupTasks`: clear output dirs, plugin setup (`webServer`, git info), `globalSetup` files. pest-e2e's auth-ticket exchange happens here | `lib/runner/index.js:6321,6354,6369` (`src/runner/tasks.ts`) | I | Yes | `globalSetup` is public | No. About 10 ms without a ticket |
| 8 | File discovery | `collectProjectsAndTestFiles`; positional args become file filters, so only matching files are kept | `lib/runner/index.js:2494,2237`, filters at `lib/common/index.js:2088` | I | Yes | Internal | No. pest-e2e already passes the spec path |
| 9 | Spec loading (runner) | `createLoadTask('in-process')` → `InProcessLoaderHost` → `loadTestFile` → `requireOrImport(spec)`; `test()` calls register into the file `Suite` | `lib/runner/index.js:6441,2072`, `lib/common/index.js:2855,2182,2236` (`src/common/testLoader.ts`, `testType.ts`) | F | Yes | Internal | No. About 55 ms with a warm transform cache |
| 10 | Project binding, `--grep`, `.only`, sharding | `createRootSuite`: project suites, `preOnlyTestFilters` (grep, locations), `filterOnly`, shard, dependency closure | `lib/runner/index.js:2560` (`src/runner/loadUtils.ts`) | I | Yes | Internal | No. Under 5 ms |
| 11 | Grouping and scheduling | `createPhasesTask` → `createTestGroups` → one `Dispatcher` per phase | `lib/runner/index.js:6502,2384,5512` (`src/runner/tasks.ts`, `testGroups.ts`, `dispatcher.ts`) | I | Yes | Internal | No. About 1 ms |
| 12 | Worker process creation | `Dispatcher._createWorker` → `WorkerHost` → `child_process.fork(workerProcessEntry.js)` with `process.env` at that moment | `lib/runner/index.js:5664,5448,1915` (`src/runner/workerHost.ts`, `processHost.ts`) | W | Yes | Internal | No |
| 13 | Worker module loading and init | The worker loads the same bundles again, then `_loadIfNeeded` → `deserializeConfig` reloads the config. **533 ms measured** | `lib/worker/workerProcessEntry.js:1355,1478`, `lib/common/index.js:1262` (`src/worker/workerMain.ts`) | W | Yes | Internal | **Only by reusing workers across runs, which Playwright does not do** (see below) |
| 14 | Spec loading (worker) | `runTestGroup` → `loadTestFile` → `bindFileSuiteToProject` → fixture pools | `lib/worker/workerProcessEntry.js:1490-1512` | F per worker | Yes | Internal | No. About 33 ms |
| 15 | Fixtures, browser, context, page | `FixtureRunner.resolveParametersForFunction`; `browser` fixture connects to `connectOptions.wsEndpoint` or launches; `context`; `page` | `lib/worker/workerProcessEntry.js:240,286`; `lib/index.js:192,211,432,450` (`src/index.ts`) | browser W; context/page T | Yes | Fixtures are public | No. About 180 ms with the warm browser, about 310 ms with a cold launch |
| 16 | Hooks | "Before Hooks" step: `beforeAll`, `beforeEach` | `lib/worker/workerProcessEntry.js:1641` | T | Yes | Public | No |
| 17 | Spec callback | `await fn(testFunctionParams, testInfo)` | `lib/worker/workerProcessEntry.js:1655` | T | — | — | — |

Two facts from the source decide the architecture:

1. **Workers never outlive a run.** `createRunTestsTask` calls `await dispatcher.stop()` after each phase and again in teardown (`lib/runner/index.js:6573,6590`). `TestRunner.runTests`, which backs the test server, UI mode and watch mode, builds the same task list (`:6856-6860`). There is no supported way to hand a second run to an existing worker.
2. **The CLI's own "run" is two calls.** `runTests()` in `lib/cli/testActions.js:42-98` does `configLoader.loadConfigFromFile(...)`, then `testRunner.runAllTestsWithConfig(config, options)`, then exits the process. Nothing in between is CLI-only.

---

## 3. Startup overhead analysis

### Conditions

- Isolated benchmark in a git-ignored scratch directory (`ShowMyWork/storage/framework/testing/pw-startup-bench/`). No package file, spec or config was modified.
- The benchmark config imports ShowMyWork's real `resources/js/e2e/playwright.config.js` and re-points only `testDir` and `globalSetup`. The global setup is the package's real `globalSetup`. The spec imports the same shared modules real specs import (`core.mjs`, `browserGuards`, `i18n`, `helpers/pageEditor`) and uses the `page` fixture against a `data:` URL.
- Invocations use the exact argv `PlaywrightWorker` builds. The warm browser is the package's real `warmBrowserServer.mjs`.
- Timestamps come from a `--require` preload in every Node process plus `DEBUG=pw:test:task`.
- Transform cache primed before measuring; 6 runs per chain; medians reported.

### Limits of these numbers

- No Laravel server, no auth ticket and no real page were involved, so per-test costs are understated. The fixed startup cost is what was measured.
- Small samples on one machine. Treat differences under about 10 % as noise. Solo runs were tight (±5 %); 12-chain runs were not (pre-warm ranged 1.5–8.3 s).
- Two contended runs failed with `ENOENT … scandir`. Both were caused by the benchmark sharing one `testDir` between concurrent runs that create and delete output directories inside it. pest-e2e passes `--output` outside `testDir`, so this is a benchmark artefact, not a finding.

### Where the time goes (fresh CLI, warm browser, idle machine)

| Phase | Elapsed at end (ms) | Cost (ms) | Process |
|---|---|---|---|
| Node boot to first user code | 40 | 40 | runner |
| **Load Playwright modules** | 773 | **733** | runner |
| Evaluate config | 788 | 15 | runner |
| Reporters, first task, global setup (no ticket) | 807 | 19 | runner |
| Discover and load the spec, build suite | 863 | 56 | runner |
| Phases, fork worker | 876 | 13 | runner |
| Worker Node boot | 913 | 37 | worker |
| **Load Playwright modules, reload config** | 1446 | **533** | worker |
| Load spec, build fixture pools | 1479 | 33 | worker |
| Worker fixtures up to `beforeEach` | 1541 | 62 | worker |
| Browser connect, context, page | 1660 | 119 | worker |
| **First test callback** | **1660** | | |
| After the test: fixture teardown, worker stop, report, exit | +197 | 197 | both |

Requiring the runner's modules one by one: `utilsBundle` 262 ms, `coreBundle` 196 ms, `lib/common` 62 ms, `lib/runner` 178 ms, MCP/agents/program 44 ms.

Under 12 concurrent invocations the runner's module load took about 1950 ms and the worker's about 1740 ms, out of 4.9 s to first callback in that run.

A cold browser launch added about 130 ms on an idle machine (1789 versus 1660), so the existing warm browser is doing its job and is not where the remaining cost is.

### Repeated, reusable, must remain

| Work | Repeated per run today | Reusable? |
|---|---|---|
| Runner module loading (733 ms) | Yes | **Yes**: keep the runner process alive |
| Worker module loading (533 ms) | Yes | **No**: workers are per-run by design |
| V8 compilation of both | Yes | Partly: `NODE_COMPILE_CACHE` (about 100 ms per process) |
| TypeScript transpile | No, already disk-cached by Playwright | — |
| Browser launch | No, already avoided by the warm browser | — |
| Config evaluation, discovery, collection, scheduling (about 100 ms total) | Yes | Must remain; too small to matter |
| Global setup and auth exchange | Yes | Must remain per run |
| Context and page | Yes | Must remain per test (isolation) |

---

## 4. Candidate architectures

### Option 1 — optimise the existing CLI invocation

- `npx` versus direct entry: already direct. No gain available.
- **`NODE_COMPILE_CACHE=<dir>`** in the Playwright child's environment (public Node feature, Node ≥ 22.1, ignored by older versions; inherited by the forked worker). Measured: 1610 → 1350 ms to callback solo, 3815 → 3231 ms at 12 chains. No change to Playwright behaviour. The cache was 1.9 MB.
- Side observation: `lib/index.js:547` honours `PW_TEST_CONNECT_WS_ENDPOINT`. pest-e2e could set that instead of relying on each consumer's config containing the `PEST_E2E_WARM_WS_ENDPOINT` `connectOptions` block. Unrelated to startup time; noted for later.

**Verdict:** adopt the compile cache regardless of what else is decided. It does not solve the problem.

### Option 2 — Playwright's `test-server`

What it is, from source:

- A hidden CLI command (`lib/program.js:79-80`, `{ hidden: true }`) that starts an HTTP/WebSocket server and prints `Listening on ws://…`. `TestServerDispatcher` (`lib/runner/index.js:7021`) wraps a `TestRunner` (`:6656`).
- It keeps the runner alive and accepts repeated `runTests` requests with `locations`, `grep`, `testIds`, `projects`, `headed`, `workers`, `reporters`, `connectWsEndpoint`.
- In this checkout it backs UI mode (`runUIMode`, `:7152`) and watch mode (`runWatchModeLoop`, `:7594`, through an in-memory transport). The MCP test backend uses `TestRunner` directly (`lib/mcp/test/testContext.js:113`). The dispatcher has a `vscode://` `open` method; I did not verify how the VS Code extension uses it.
- It reloads config per request but does not cache discovery. It does not reuse workers. It loads specs in a **separate loader process** (`createLoadTask('out-of-process')`, `:6858`; `OutOfProcessLoaderHost`, `:2089`).

Why it is rejected:

1. **It is not fully native.** `_innerRunTests` hard-codes `repeatEach: 1, retries: 0` (`:6815-6816`), and that override beats both config-level and project-level `retries` (`lib/common/index.js:591`). Native retries are silently disabled.
2. **Little saving.** The loader process costs about 590 ms, nearly what the runner's module load cost. Measured 1404 ms to callback versus 1610 ms. Two Node processes are still spawned per run.
3. **No per-run environment.** The protocol has no env parameter. pest-e2e passes run ID, params, auth ticket and report directory through environment variables.
4. **No per-run output directory**, and global setup is a separate call that the client must sequence.
5. Hidden, undocumented protocol.

### Option 3 — persistent runner on Playwright's internal run entry (recommended, subject to approval)

One Node process per Pest worker. For each request it applies the per-run environment, then calls exactly what the CLI calls:

```js
const config = await configLoader.loadConfigFromFile(configFile, cliOverrides);   // playwright/lib/common
const status = await testRunner.runAllTestsWithConfig(config, { locations, grep }); // playwright/lib/runner
```

then restores the environment and reports the status. The experiment that did this is 30 lines (appendix A).

- **Savings and their source:** the runner's module load disappears from every run after the first, so each run loads Playwright in one process instead of two, which is consistent with the gain holding under load (2194 versus 3815 ms at 12 chains). One Node process is spawned per run instead of two.
- **Verified in the experiment:** per-run `--grep` on the same spec file selects the right test each time; a native `retries: 1` flaky test reports `failed/passed` and `flaky: 1`; a non-matching grep yields Playwright's own "No tests found" error; the JSON reporter writes a normal report per run.
- **Cost:** two internal functions and the shape of their arguments, plus the differences in section 6.

### Option 4 — other mechanisms found

**4a. Pre-warmed single-use CLI.** Start a Node process early that does `require('playwright/lib/program')` and then waits for one line on stdin carrying argv and per-run env before calling `program.parse()`. This is the genuine CLI, used once, so it has no cross-run state and no semantic differences at all. It depends only on `lib/program`, the entry `@playwright/test/cli.js` itself uses, exported unchanged from 1.45 to 1.63.

Measured: 881 versus 1610 ms solo and 1978 versus 2492 ms at 6 chains, but **no gain at 12 chains** (3542 versus 3815 ms to callback, and total wall time slightly worse). It moves the work earlier rather than removing it, so it only helps when CPU is idle. Viable as a conservative fallback if the internals in Option 3 are judged unacceptable.

**4b. Repeated in-process runs with spec reload, V8 startup snapshots.** Not pursued. Snapshots would need Playwright's bundles to be snapshot-safe, which nothing in the source suggests; not tested.

**4c. Reusing workers across runs.** Not available without replacing `Dispatcher`. Rejected under the "do not reimplement the scheduler" rule.

### Comparison

| Criterion | 1: compile cache | 2: test-server | 3: persistent runner | 4a: pre-warmed CLI |
|---|---|---|---|---|
| Native API compatibility | Identical | **Retries forced to 0** | Native, 3 small differences | Identical |
| Callback time, solo / 12 chains | −16 % / −15 % | −13 % / −8 to −15 % | **−49 % / −42 %** | −45 % / −7 % |
| Fits current `JsWorkerContract` | Env var only | New client + protocol | New worker implementation | New worker implementation |
| Per-worker isolation | Unchanged | One server per worker | One runner per worker | One process per run |
| Admission / managed server | Unchanged | Unchanged | Unchanged | Pre-warm must not bypass admission |
| Warm browser | Unchanged | Static endpoint only | Unchanged (env per run) | Unchanged |
| Auth ticket | Unchanged | Needs a file side-channel | Per-run env, restored after | Per-run env |
| Reporting, artifacts | Unchanged | No per-run output dir | JSON to file per run | Unchanged (stdout) |
| Cleanup, crash recovery | Unchanged | Server supervision | Runner supervision + CLI fallback | Kill unused pre-warm |
| Playwright API surface used | None | Hidden WS protocol | 2 internal functions | `lib/program` only |
| Implementation risk | Very low | High | Medium | Low–medium |

---

## 5. Native compatibility assessment

Under Option 3, every item below is executed by Playwright's own code with no emulation, no spec rewrite and no patched API:

- `import { test, expect } from '@playwright/test'`; `test`, `describe`, all hooks, `skip`/`fixme`/`fail`/`slow`, `step`, `use`, `extend`.
- Fixtures and dependency resolution; auto-retrying assertions (worker process, unchanged).
- Projects, project dependencies, tags, annotations, `--grep`, `.only`, sharding (`createRootSuite`, unchanged).
- Retries and timeouts (confirmed by experiment for retries); tracing, screenshots, video (worker fixtures, unchanged).
- Reporters, including custom ones and the JSON reporter pest-e2e parses.
- `globalSetup`/`globalTeardown` and `webServer`, each run.
- Future Playwright Test features, as long as they live behind `runAllTestsWithConfig`.

What is **not** covered: `--ui`, watch mode, and `--debug`/headed sessions. Those should keep using the cold CLI path.

---

## 6. Risks

**Observable differences from the CLI under Option 3 (all confirmed):**

1. **`workerIndex` increments across runs.** `lastWorkerIndex` is module state (`lib/runner/index.js:5447`); the experiment reported 1, 2, 3… where the CLI always reports 0. `TEST_WORKER_INDEX` and `testInfo.workerIndex` follow it. `parallelIndex` stays 0. UI mode has the same behaviour.
2. **The config module is evaluated once per runner process.** Node caches the import, so values computed from environment variables at config top level are frozen in the runner. The experiment showed `use.storageState` stuck at the first run's path in the runner process. The **worker** is a fresh process and re-evaluates the config with the current environment, so `use.*` (storage state, base URL, connect options) was correct where it is consumed. Fields the runner itself uses (`outputDir`, reporters, `testDir`, `projects`, `globalSetup` path) would be stale if a consumer derives them from per-run variables. `--output` is already passed as an override, which covers the published stub config.
3. **Collected suites are cached per file for the life of the runner** (`cachedFileSuites`, `lib/common/index.js:2854`). A spec edited while a Pest run is in progress would be collected stale in the runner but fresh in the worker.

**Other risks:**

- **Internal API instability.** `runAllTestsWithConfig` exists at `v1.56.0` and `v1.63.0` but not at `v1.50.0`, where the equivalent was a `Runner` class in `runner.ts`; the `./lib/runner` export also did not exist at 1.50. The override and option object shapes are internal too. Mitigation: gate on tested Playwright versions and fall back to the CLI automatically for anything else.
- **Environment leakage between runs.** The package's `globalSetup` writes `PEST_E2E_AUTH_TOKEN`, `PEST_E2E_STORAGE_STATE` and `PEST_E2E_AUTH_STRATEGY` into `process.env`. In a persistent process those would carry into the next run, including a Sanctum token into an unauthenticated run. The runner must snapshot and restore the full environment around every run. This is a correctness requirement, not an optimisation.
- **Process isolation.** One runner per Pest worker, never shared, requests strictly serialised.
- **Reporting.** stdout of a long-lived process is no longer one report. Use `PLAYWRIGHT_JSON_OUTPUT_FILE` per run (it worked in the experiment) and frame the control channel.
- **Crash, hang and cleanup.** A reporter or config exception, or a hung run, must kill the runner and its worker, fail that run with today's diagnostics, and respawn or fall back. Shutdown needs the same suite-end and shutdown-handler treatment as `WarmBrowserManager`.
- **Concurrency.** Not a new risk if serialisation holds; admission control stays in front of every request.

---

## 7. Authentication lifecycle

Today: `actingAs()` records identity → admission → warm browser → `lateParams` issues the ticket → `jsWorker->run()` (`E2ERunner::run`, `E2ETargetHandle::run`).

With a persistent runner, "launch" becomes "write the run request to the runner". The correct order is:

1. Admission.
2. Warm-browser endpoint.
3. **Ensure the runner process is alive and ready** (first-use startup can take 0.6–1.8 s under load and must not count against the ticket).
4. Issue the ticket (`lateParams`).
5. Send the request immediately.

Everything else is preserved unchanged: TTL, single-use exchange in `globalSetup`, guard/user binding, the testing-only route, and revocation of an unexchanged ticket in the existing `finally`. Per-worker separation follows from one runner per `TEST_TOKEN`. Cross-run leakage is prevented by the environment restore described above. The ticket travels as per-run environment exactly as now; no endpoint or check is weakened.

For the pre-warmed CLI (4a) the same rule applies: wait for the pre-warm to be ready, then issue, then release it.

---

## 8. Compatibility with existing pest-e2e features

| Feature | Option 3 impact |
|---|---|
| Normal Pest browser tests, `only()`, `spec()`, `runTest()` | No API change; maps to `locations` and `grep` |
| Parallel Pest execution | One runner per worker, keyed like `WarmBrowserManager` |
| Admission control, managed servers | Unchanged; they run before the request |
| Warm browser | Unchanged; endpoint is per-run env |
| `actingAs()` / auth | Boundary moves as in section 7 |
| Config overrides (`--workers=1`, `--output`, `--reporter json`) | Passed as the override object instead of argv |
| Screenshots, video, traces | Unchanged (worker) |
| JSON report | Read from a per-run file instead of stdout |
| `--debug`, `--browse` | Route to the cold CLI |
| Cleanup, crash recovery | New supervision code, modelled on `WarmBrowserManager` |
| `JsWorkerContract` binding | New implementation; `PlaywrightWorker` stays as default and fallback |

Consumers beyond ShowMyWork: anyone whose config computes runner-side fields from per-run environment would see difference 2. That is why this should be opt-in. `config/pest-e2e.php` and the README already document `js_runner.mode` (`cold`/`warm`, default `cold`), and no code reads it yet, so the switch already exists in the public config.

---

## 9. Recommended architecture

1. **Now, independent of the rest:** set `NODE_COMPILE_CACHE` for the Playwright child process.
2. **Main change, opt-in:** a persistent per-worker runner (Option 3) behind `js_runner.mode = warm`, restricted to Playwright versions the package has tested, with automatic fallback to the current CLI path for unknown versions, runner failures, `--debug` and `--browse`.
3. **If the internal dependency is not acceptable:** the pre-warmed CLI (4a) instead. It is fully native and low-risk, but only helps when CPU is not saturated.

I would not build on `test-server`.

## 10. Proposed implementation phases

1. **Compile cache.** One env var, a config key to disable it, a benchmark before and after on the real suite.
2. **Runner script and contract tests.** The Node runner (request loop, env snapshot/restore, per-run JSON file, version check) with JS-level tests for: env restore, grep selection, retries, no-tests error, crash exit.
3. **PHP side.** `PersistentPlaywrightWorker implements JsWorkerContract` plus a manager modelled on `WarmBrowserManager` (per-worker instance, ready handshake, timeout kill, shutdown handlers). Opt-in only.
4. **Auth boundary.** Move ticket issuance behind "runner ready"; extend the existing queued-ticket tests.
5. **Fallback and version gate.** Unknown Playwright version or runner failure → cold CLI, with one diagnostic line.
6. **Verification on ShowMyWork**, serial and `--parallel`, comparing reports between cold and warm for the whole browser suite.
7. **Document the three differences**; decide on default only after that.

## 11. Acceptance criteria

- **Faster:** on the real ShowMyWork browser suite, median time from request to first callback and total suite wall time, cold versus warm, at 1 worker and at the usual parallel worker count, using the existing `PEST_E2E_TIMING` probes.
- **Native:** the same suite produces identical pass/fail/flaky/skip results and equivalent JSON reports in both modes; a dedicated fixture spec covers retries, `test.fail`, `test.skip`, `beforeAll`/`afterAll`, `test.use`, a custom fixture, tags with `--grep`, and trace/screenshot artifacts on failure.
- **Isolated:** an authenticated run followed by an unauthenticated run in the same runner shows no session or token; two workers never share a runner.
- **Recoverable:** killing the runner mid-run fails that test with a clear message and the next test passes.
- **Unchanged specs:** zero diffs to any `.spec.ts` or consumer config.

## 12. Open questions for Andy

1. **Is a dependency on two undocumented Playwright functions acceptable**, given a version gate and automatic CLI fallback? This decides Option 3 versus 4a.
2. **Are the three differences in section 6 acceptable** for an opt-in mode, with documentation?
3. **Ship `NODE_COMPILE_CACHE` now**, on by default? Where should the cache directory live (per project under the report base dir, or system temp)?
4. **Which Playwright versions does pest-e2e support?** The gate needs a floor; `runAllTestsWithConfig` does not exist at 1.50.
5. The remaining ~0.5 s per run is the worker process and cannot be removed natively. Is roughly halving the tax enough to justify the work, or should the effort stop at the compile cache?
6. Should this report stay on `fix/parallel-auth-ticket-lifetime` or move to its own branch?

---

## Appendix A — experiment sources

The full scripts are in `ShowMyWork/storage/framework/testing/pw-startup-bench/` (git-ignored): `bench-cli.mjs` (phase breakdown), `bench-prewarm.mjs`, `bench-server.mjs`, `bench-inproc.mjs`, `validate-inproc.mjs`. Run them inside the Sail container with `node <script>`; each prints its usage in a header comment.

Persistent runner core (`inproc-server.cjs`):

```js
const { configLoader } = require('playwright/lib/common');
const { testRunner } = require('playwright/lib/runner');
const baselineEnv = { ...process.env };
function restoreEnv() {
  for (const k of Object.keys(process.env)) if (!(k in baselineEnv)) delete process.env[k];
  for (const [k, v] of Object.entries(baselineEnv)) if (process.env[k] !== v) process.env[k] = v;
}
async function run(req) {
  let status = 'failed', error;
  try {
    Object.assign(process.env, req.env);
    const config = await configLoader.loadConfigFromFile(req.config, { workers: '1', reporter: [['json']], outputDir: req.outputDir, use: {} });
    status = await testRunner.runAllTestsWithConfig(config, { locations: [req.spec], grep: req.grep });
  } catch (e) { error = String(e && e.stack || e); } finally { restoreEnv(); }
  process.stdout.write('\x00DONE ' + JSON.stringify({ id: req.id, status, error }) + '\n');
}
```

Pre-warmed CLI core (`prewarm.cjs`):

```js
const { program } = require('playwright/lib/program');   // same require as playwright/cli.js
process.stdout.write('\x00READY\n');
// on one stdin line { argv, env }:
Object.assign(process.env, env);
process.argv = [process.argv[0], cliPath, ...argv];
program.parse(process.argv);                              // same call as playwright/cli.js
```
