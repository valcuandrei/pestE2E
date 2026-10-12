# Persistent Playwright runner — isolation follow-up

> **Status:** follow-up investigation complete, awaiting Andy's review. No production code, spec, config or dependency was changed.
> **Owner:** Andy
> **Worker:** Claude (Opus 5.5)
> **Date:** 2026-10-13
> **Supersedes:** the recommendation in `docs/native-playwright-runner-investigation.md` (commit `05e7970`), sections 1, 4 (Option 3), 6 and 9
> **Versions:** Playwright 1.63.0, Node 24.16.0 (ShowMyWork Sail container)

Source references use `lib/<bundle>.js:<line>` for the installed 1.63.0 bundle under `node_modules/playwright/`, followed by the upstream file under `packages/playwright/src/`.

Statements are labelled **[fact]** (read in source or Node behaviour), **[observed]** (measured or run here) or **[hypothesis]**.

---

## 1. Answer

**Can pest-e2e safely reuse Playwright Test's parent runner across executions with exactly the semantics of separate CLI invocations? No.**

My earlier recommendation of a persistent `runAllTestsWithConfig` runner is withdrawn. Astra's Finding B is valid, and the problem is larger than either of us had shown:

- A persistent parent retains three things from the first run that a fresh CLI would rebuild: the collected test suites, the evaluated config and spec modules, and the transpiled source of every file it loaded.
- The third one is shipped to each new worker, so **the "fresh" worker executes stale code after a spec or helper is edited**, and the run passes.
- Of 28 native scenarios, the persistent prototype matched the CLI in 12. Several mismatches are silent: the wrong test runs and passes, a test that should run is reported skipped, or old code runs.
- Playwright has no reset for any of this, guards against being loaded twice in one process, and Node cannot evict ESM modules at all.

The safest alternative is **fresh CLI processes plus `NODE_COMPILE_CACHE`** (13–16 % faster, no semantic change). The **pre-warmed single-use CLI** matched the CLI in all 28 scenarios in both module systems and is correct by construction, but its speed benefit exists only when CPU is spare.

---

## 2. Were Astra's experiments valid?

Yes. I copied `compat.cjs`, `compat.config.cjs`, `compat.spec.cjs`, `state.config.cjs` and `state.spec.cjs` unmodified into `step32-opus-followup/astra-repro/` and ran `node compat.cjs` three times in the Sail container. Astra's directory was not written to; checksums of its originals are in `step32-opus-followup/astra-original.sha1`.

**[observed]** Identical results in 3 of 3 runs, exit code 0 each time:

| Experiment | Fresh CLI | Persistent |
|---|---|---|
| `compat` (`retries: 1`, `repeatEach: 2`, test passes only on retry 1) | exit 0, `flaky: 2`, each repetition `failed@retry0, passed@retry1` | `TestRunner.runTests`: `status: failed`, one result, `retry: 0` |
| `state` request A | passes, metadata `A` | `runAllTestsWithConfig`: passes, metadata `A` |
| `state` request B | passes, title `request B`, metadata `B` | `runAllTestsWithConfig`: **failed**, parent metadata still `A`, "Test not found in the worker process. Make sure test title does not change." |

**Finding A is the limitation already recorded, not a new issue.** `compat.cjs` uses `new testRunner.TestRunner(...).runTests(...)`, which is the test-server path. That path hard-codes `repeatEach: 1, retries: 0` (`lib/runner/index.js:6815-6816`, `src/runner/testRunner.ts`). It says nothing about `runAllTestsWithConfig`, which kept native retries and `repeatEach` in every run here (section 6).

**Finding B is real and concerns the architecture I recommended.** One caveat on the experiment itself: `state` changes two things at once (the test title and the config metadata), and its test body asserts on metadata. The title mismatch fails first and hides a second failure behind it. Section 6 separates them.

---

## 3. Why the earlier Opus experiment passed

**[observed]** My earlier validation varied only things that reach Playwright as run options or are read at run time: `--grep`, the spec path, per-run environment read inside test bodies and `globalSetup`. The spec files, their titles and the config were identical in every run.

In that situation the stale state in the parent is indistinguishable from fresh state: the cached suite contains the same tests with the same IDs the worker computes, and grep filtering is applied to a clone on each run. The matrix confirms this still holds (`repeat-same`, `grep-selection`, `different-files`, hooks, fixtures all match the CLI).

I documented the config cache and the suite cache as "differences" but tested neither with a changing input, and I described the worker as fully fresh. That last claim was wrong (section 4, R3).

**[observed]** ShowMyWork's current specs read params inside test bodies and have no registration-time environment use, so the ShowMyWork suite sits in the safe subset today. That is why nothing looked wrong, and it is not a property a public package can rely on.

---

## 4. Root cause

### How identities are assigned and matched **[fact]**

1. `test()` registers a `TestCase` into the file `Suite` currently being loaded (`lib/common/index.js:2236`, `src/common/testType.ts`).
2. `bindFileSuiteToProject` clones the file suite and assigns each test
   `id = sha1(relative file path)[0:20] + '-' + sha1('[project=' + projectId + ']' + file + titles…)[0:20]`
   (`lib/common/index.js:2023-2033`, `src/common/suiteUtils.ts`). Retries, timeout and skip annotations are also fixed here. `repeatEach` appends ` (repeat:N)` (`:2058-2066`).
3. The parent dispatches test groups to the worker as a file path plus a list of `testId`s.
4. The worker loads the file itself, binds it to its own copy of the project, and keeps only tests whose ID is in the list. IDs it cannot find become `fatalUnknownTestIds` (`lib/worker/workerProcessEntry.js:1490-1509`, `src/worker/workerMain.ts`).
5. The dispatcher turns those into "Test not found in the worker process" (`lib/runner/index.js:5897-5901`, `src/runner/dispatcher.ts`). A missing project produces "Project … not found in the worker process".

So the parent and the worker each derive identities independently, and Playwright assumes both evaluate the same code under the same inputs. A fresh CLI guarantees that. A persistent parent does not.

### The retained state

| | Retained object | Where | Effect on run B |
|---|---|---|---|
| **R1** | `cachedFileSuites`, a module-private `Map<file, Suite>`. `loadTestFile` returns the cached `Suite` without loading anything | `lib/common/index.js:2854-2857` (`src/common/testLoader.ts:32-36`) | Parent keeps run A's tests, titles, annotations, `describe.configure` values |
| **R2** | Node's module caches for the config, specs and their imports: `require.cache` (CJS) or the ESM module map | Node | Config is evaluated once; modules would not re-run `test()` even without R1 |
| **R3** | The compilation `memoryCache`, a `Map<filename, {codePath,…}>`. A hit returns the cached transpiled code **without comparing the content hash**. The parent serialises this cache into every worker it starts | `lib/common/index.js:135-141, 169-177` (`src/transform/compilationCache.ts`); hand-off at `lib/runner/index.js:5597` and `lib/common/index.js:1262-1264` | **The new worker runs run A's source** for any file the parent loaded |
| **R4** | `config.metadata` is passed from the parent to the worker rather than re-evaluated | `lib/common/index.js:1788-1795, 1265, 518` | Worker sees stale metadata |
| **R5** | `lastWorkerIndex` counter | `lib/runner/index.js:5447` | `workerIndex` increments (already reported) |

**[observed]** `matrix/probe-identity.cjs` shows R1–R3 directly, in both CJS and ESM projects. After run A with `REQ=A`, in the same process with `REQ=B`:

- `loadTestFile` returns the very same `Suite` object, still titled `request A`, ID `4bb17c2992a2b9ccc470-519f975fb73aa5a79be0`.
- A fresh process (what the worker computes) gets `request B`, ID `4bb17c2992a2b9ccc470-fbfbfe4c4cb51237a89f`. Same file hash, different title hash.
- The parent's compilation cache entry for the spec is unchanged after the spec file is edited on disk.

### What triggers a failure

| Change between run A and run B | Result in the persistent prototype |
|---|---|
| Nothing (same run repeated) | Correct |
| Run options only: `--grep`, spec path | Correct |
| Environment read inside test bodies, fixtures, `globalSetup`, file-level `test.use()` | Correct |
| Environment used in config `use.*` or `timeout` | Correct (worker re-evaluates the config) |
| Environment used in a test title or to generate tests | **Visible failure**: "Test not found in the worker process" |
| Environment used for file-level `test.skip()` or `describe.configure()` | **Silent**: stale skip state / stale retries |
| Environment used in config `grep`, `projects`, `retries`, `repeatEach`, `reporter`, `outputDir`, `metadata` | Mostly **silent**: stale value; `projects` fails visibly |
| Spec or helper file edited | **Silent**: old code runs and passes |
| Spec fixed after a load error | Stays failed with the old error |

A fixture change behaves like a file edit (stale). A project change behaves like a config change.

---

## 5. Configuration isolation

**[observed]** Config computed from `process.env` at module evaluation, run A then run B with different values (`cfg-env-*` scenarios):

| Field | Evaluated by | Persistent prototype, run B |
|---|---|---|
| `use.baseURL`, `use.storageState`, `timeout` | Worker (fresh process, config re-evaluated) | Correct |
| `metadata` | Parent, then passed to the worker (R4) | Stale in both the report and `testInfo.config` |
| `grep` | Parent | **Stale: ran and passed `alpha` when the CLI ran `beta`** |
| `projects` | Both, independently | Fails: "Project "pa" not found in the worker process"; a later run silently dropped a project |
| `retries` | Parent decides whether to retry | Stale: flaky test reported as failed |
| `repeatEach` | Parent | Stale: 1 repetition instead of 3 |
| `reporter` | Parent | Stale: JSON written when the CLI wrote JUnit |
| `outputDir` | Parent cleans it; worker writes into it | Split: worker wrote to `out-B`, parent deleted `out-A` |

Explicit run overrides (`--output`, `--grep`, `--workers`, `--reporter`) are applied per run and are correct. That is why the package's stub config, whose `outputDir` depends on the run ID but is overridden by `--output`, did not expose the problem.

Run B does **not** receive the configuration a fresh CLI would give it. Playwright's own `TestRunner` has the same config staleness (it re-calls `loadConfig`, which hits the same module cache).

---

## 6. Native compatibility matrix

Harness: `ShowMyWork/storage/framework/testing/step32-opus-followup/matrix/`. Every scenario is ordinary `@playwright/test` TypeScript. Each is run as a sequence of steps by four runners in separate directory copies, and each step's normalised result (exit status, tests with outcome and attempts, annotations, report metadata, errors, values observed inside tests, output tree) is compared with the fresh CLI's.

- **CLI**: `@playwright/test/cli.js test -c …` per step.
- **TestRunner**: Playwright's built-in persistent runner (`runGlobalSetup` + `runTests`).
- **Prototype**: one process, `loadConfigFromFile` + `runAllTestsWithConfig` per step, environment restored after each.
- **Pre-warm**: single-use CLI process that has loaded `playwright/lib/program` and waits for argv and env. The process for step N+1 is started and fully loaded before step N runs, so it is alive across the previous run, the environment change and any file edit.

Both flavours were run: `"type": "commonjs"` (Playwright's CJS transform) and `"type": "module"` (Playwright's ESM loader). **[observed]** Results were identical between flavours and identical across two complete runs (216 of 216 cells).

| Scenario | CLI | TestRunner | Prototype | Pre-warm |
|---|---|---|---|---|
| Same spec and config, repeated ×3 | pass | same | same | same |
| Same spec, different `--grep` | correct selection | same | same | same |
| Different spec files in succession | no stale collection | same | same | same |
| `test.describe()`, all four hooks, `test.step`, `test.fixme` | correct order and teardown | same | same | same |
| `test.extend()` worker and test fixtures, option fixture, `test.use` | correct setup and teardown | same | same | same |
| Failed run, then passing run, then failed | independent | same | same | same |
| Authenticated then unauthenticated (`globalSetup` sets a token in env) | no leak | same | same (needs env restore) | same |
| Two concurrent "Pest workers" | isolated | same | same | same |
| File-level `test.use()` from env | fresh | same | same | same |
| Config `use.*`/`timeout` from env | fresh | same | same | same |
| Native retries (static config) | flaky, 2 attempts | **differs** (1 attempt, failed) | same | same |
| Native `repeatEach` (static config) | 2 repetitions | **differs** (1) | same | same |
| Config `metadata` from env | fresh | **stale** | **stale** | same |
| Config `outputDir` from env | fresh | **split** | **split** | same |
| Config `grep` from env | fresh | **wrong test passes** | **wrong test passes** | same |
| Config `projects` from env | fresh | **fails / drops project** | **fails / drops project** | same |
| Config `retries` from env | fresh | **stale** | **stale** | same |
| Config `repeatEach` from env | fresh | **stale** | **stale** | same |
| Config `reporter` from env | fresh | **stale** | **stale** | same |
| Test title from env | correct identity | same | **"Test not found"** | same |
| Tests generated from env | correct set | same | **one not found, one missing** | same |
| File-level `test.skip()` from env | runs when it should | same | **reported skipped** | same |
| `describe.configure({retries})` from env | fresh | same | **stale** | same |
| Spec body edited | new code runs | same | **old code runs, passes** | same |
| Spec titles edited, test added | new tests run | same | **old test runs, passes** | same |
| Helper edited, used at run time | new value | same | **old value** | same |
| Helper edited, used in a title | new title | same | **old title, passes** | same |
| Load error, then fixed | passes after fix | differs (error text only) | **stays failed** | same |
| **Matches CLI** | — | 18 of 28 | **12 of 28** | **28 of 28** |

Can a source-supported isolation approach preserve the semantics? For the two persistent columns, no (section 7). The pre-warm column needs none.

---

## 7. Is per-run isolation achievable inside a persistent process?

**No, not without patching Playwright and replacing part of its loader.**

- **R1 has no reset. [fact]** `cachedFileSuites` is a module-private `const` with no exported accessor in 1.63.0. Playwright's own answer to stale collection is to load specs in a separate process (`createLoadTask('out-of-process')`), which is only reachable through `TestRunner` and therefore comes with forced `retries: 0`. It also costs about as much as the process it replaces (590 ms loader versus 730 ms runner load, measured in the first investigation), so the saving disappears.
- **R3 has no reset. [fact]** `memoryCache` is module-private; the exported `cc` object can add to it and serialise it, not clear it.
- **Reloading Playwright's test layer is blocked. [observed]** Evicting `node_modules/playwright/lib/**` and user modules from `require.cache` and re-requiring fails on the second run with "Requiring @playwright/test second time", raised by a guard on `process["__pw_initiator__"]` (`lib/index.js:58-67`, `src/index.ts`). Getting past it means deleting another undocumented global, and would also re-register Playwright's ESM loader hooks and source-map support on every run.
- **ESM cannot be reset at all. [fact]** Node exposes no way to evict or re-evaluate an ES module. `requireOrImport` builds the import URL from the file path (`lib/common/index.js:1072-1081`), so there is no place to add a cache-busting query without supplying our own loader hook in front of Playwright's. The package's stub config and most modern specs are ESM syntax; in `"type": "module"` projects such as ShowMyWork they load as real ES modules.
- **R2 for the config has no reset either.** The config would need the same eviction, with the same ESM dead end.

A working repair would therefore have to: reach into at least three private module-level variables, defeat the double-load guard, and intercept ESM resolution for user files. **[hypothesis]** Each is likely to break on routine Playwright releases; the first investigation already showed the internal entry point itself changed between 1.50 and 1.56. That is rebuilding Playwright's loader, which the brief rules out.

Detecting unsafe cases instead of repairing them does not work either. File edits could be detected by hashing loaded files, but registration-time and config-time use of environment or params cannot be detected without collecting in a fresh process, which is the cost being avoided. The failure mode when detection misses is a silent wrong pass.

---

## 8. Architecture re-evaluation

### A. Persistent `runAllTestsWithConfig` — rejected

Correct only when the config, every spec and every imported file are byte-identical between runs and nothing evaluated at config or registration time depends on per-run input. pest-e2e cannot verify that contract for its consumers, and violations are mostly silent. The measured 42–49 % saving stands as a measurement but is not achievable correctly.

### B. Pre-warmed single-use CLI — correct; benefit depends on spare CPU

**Why it is correct.** Each process serves exactly one run and then exits, so there is no second run to contaminate. The only question is what the process did before it was given the run:

- **[observed]** `matrix/audit-prewarm.cjs`: after `require('playwright/lib/program')`, 27 modules are loaded and **none is outside `playwright`, `playwright-core` or `@playwright/test`**. No config, spec, helper or `globalSetup` has been touched.
- **[observed]** About 130 environment variable names are consulted during that load: CI detection, `DEBUG*`, colour/TTY variables, `PWDEBUG`, `PWTEST_*`, `PLAYWRIGHT_BROWSERS_PATH`, `NODE_ENV`, `TZ`, `PATH`, `HOME`. No `PEST_E2E_*` name is among them.
- **[observed]** 28 of 28 scenarios match the CLI in both module systems, with the next process already loaded before each environment change and file edit.

**The one rule it needs.** The process must be started with the complete environment the run will use, except for a fixed allow-list of pest-e2e's own per-run variables (run ID, params, params file, report directory). If the run's environment differs from the spawn environment in anything else, discard the pre-warmed process and start cold. The working directory is fixed at spawn. `--debug` and `--browse` use the cold path.

**Benefit. [observed]** Medians, request to first callback, fresh CLI → pre-warm:

| Load | No gap between runs | 1.5 s gap between runs |
|---|---|---|
| 1 chain | 1610 → 881 ms | 1786 → 981 ms |
| 6 chains | 2492 → 1978 ms | 2598 → 2324 ms |
| 12 chains (12 logical CPUs) | 3815 → 3542 ms | 4743 → 5062 ms, with a tail up to 14.7 s |

It moves module loading earlier; it does not remove it. With idle cores that is a 45 % cut. At CPU saturation there is no gain and, in the gap test, a worse tail. The 12-chain gap runs were taken with other load on the machine (load average 1.8–3.4 beforehand) and all chains were synchronised, so treat them as a worst case, not a precise figure.

It also needs process management: a ready handshake, killing unused spares at suite end, and not pre-warming when admission control says the host is under pressure.

### C. Fresh CLI with `NODE_COMPILE_CACHE` — recommended now

No change to process model or semantics. **[observed]** 1610 → 1350 ms solo and 3815 → 3231 ms at 12 chains in the first investigation; 4743 → 3976 ms at 12 chains with gaps in this one. Astra measured about 11 % solo and 19 % at six chains independently. Requires Node 22.1 or later and is ignored on older versions.

B and C combine: 6 chains with gaps measured 2598 → 2009 ms with both.

### D. Other architectures

None found that keeps Playwright's engine and removes the parent's load. An out-of-process collector is what `TestRunner` does and it costs what it saves. Worker reuse across runs remains unavailable.

### Revised numbers

| Approach | Correct? | Time to first callback versus today |
|---|---|---|
| Fresh CLI (today) | Yes | baseline |
| C: compile cache | Yes | about −15 %, holds under load |
| B: pre-warm | Yes | −45 % idle, −10 to −20 % at 6 chains, none at saturation |
| B + C | Yes | about −23 % at 6 chains with gaps |
| A: persistent runner | **No** | (−42 to −49 %, not usable) |

---

## 9. Updated recommendation

1. **Ship `NODE_COMPILE_CACHE`** for the Playwright child process, with a config switch to turn it off.
2. **Do not build the persistent runner.** Leave `js_runner.mode` unused or remove the `warm` value from the docs until something backs it.
3. **Treat the pre-warmed CLI as an optional second step**, only if a measurement on the real suite at Andy's normal parallelism shows spare CPU for it to use. If built, it must follow the spawn-environment rule above and be gated by admission control.
4. The startup tax cannot be eliminated natively. The honest ceiling for a correct solution is roughly 15 % always and up to 45 % when cores are idle.

## 10. Decisions for Andy

1. Approve shipping the compile cache? Default on or off, and where should the cache directory live?
2. Confirm the persistent runner is dropped, including for ShowMyWork-only use. (It would work for today's ShowMyWork specs, but only by an unenforced convention.)
3. Is the pre-warmed CLI worth a measured trial on the real suite, given that it helps only with CPU headroom?
4. What should happen to `js_runner.mode` (`cold`/`warm`), which is documented but unimplemented?
5. Repository housekeeping I did not touch: `docs/native-playwright-runner-investigation.md` is deleted in the working tree but still committed, and Astra's draft is untracked at `.docs/native-playwright-runner-investigation-astra.md`. `.docs/` ships to consumers; `docs/` does not.

---

## Appendix — reproducing

All experiments are under `ShowMyWork/storage/framework/testing/step32-opus-followup/` (git-ignored). Run inside the Sail container from `matrix/`:

| Command | Purpose |
|---|---|
| `node run-matrix.cjs all cjs,esm` | Full matrix; writes `results.json` |
| `node show-diffs.cjs [regex]` | Per-step differences from the CLI |
| `node probe-identity.cjs cjs` (or `esm`) | Retained objects and the two test IDs |
| `node audit-prewarm.cjs` | Modules and env vars touched before a pre-warmed run |
| `RUNNERS=cli,evict RESULTS=results-evict.json node run-matrix.cjs all` | The failed cache-eviction repair |
| `../astra-repro/`: `node compat.cjs` | Astra's experiment, unmodified copy |

`results-run1.json` and `results.json` are the two full matrix runs; `matrix-run2.txt` is the second run's summary. Astra's original directory `step32-astra/` was read but not modified.
