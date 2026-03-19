# pestE2E Speed Optimization Plan

## Objective

Reduce E2E runtime from ~7–13 seconds per Pest test to:

- Cold run: ~4–6 seconds
- Warm run: ~2–3 seconds for repeated tests

while preserving the core package principles:

- PHP orchestrates the test lifecycle
- JavaScript owns the browser
- The core remains runner-agnostic
- The public PHP API remains unchanged
- The canonical `pest-e2e.v1` JSON report remains unchanged

---

# Core Problem

Current execution path:

Pest Test
→ Laravel server boot
→ Node runner start
→ Playwright CLI bootstrap
→ Browser launch
→ Test execution (~0.6–1s)
→ Report conversion

Observed cost distribution:

| Component | Time |
|----------|------|
| Playwright bootstrap | 3–5s |
| Laravel server boot | ~1s |
| Browser launch | ~1s |
| Actual test | <1s |

Most time is spent in **cold startup overhead**.

---

# Architecture Strategy

Introduce a **JS runner abstraction layer** so optimizations can happen inside the runner without coupling the core package to Playwright.

Core idea:

```

PHP (Orchestrator)
↓
JsWorkerContract
↓
PlaywrightWorker

```

Two implementations (planned):

```

PlaywrightColdRunner
PlaywrightWarmRunner

```

Cold runner behaves exactly like today.
Warm runner reuses a persistent browser.

---

# [x] Phase 0 — Baseline Measurement

## Tasks

Add timing instrumentation for:

- [x] PHP test start
- [x] server runner start
- [x] server ready
- [x] JS runner spawn
- [x] Playwright bootstrap
- [x] test execution
- [x] report conversion

Instrumentation format (opt-in):

- Enable with `PEST_E2E_TIMING=true`
- Marker prefix: `[pest-e2e:timing]`
- Emitted as JSON payload lines to `stderr` from both PHP and JS paths

## Benchmarks

Run measurements for:

- 1 Pest test → 1 Playwright test
- 5 sequential tests
- failure path
- debug / browse mode

## Target metrics

Cold run ≤ 6s
Warm run ≤ 2s

---

# [x] Phase 1 — Introduce JsWorkerContract

## Goal

Create a runner abstraction without changing behavior.

## Actual Implementation

The package uses `JsWorkerContract` (not JsRunnerContract) with a simpler interface:

```
packages/pestE2E/src/Contracts/JsWorkerContract.php
```

### Interface

```php
interface JsWorkerContract
{
    public function run(ProcessPlanDTO $plan): ProcessResultDTO;
}
```

### DTOs

The implementation uses existing DTOs:

* `ProcessPlanDTO` — command, options, test filter, headed/debug flags
* `ProcessResultDTO` — exit code, stdout, stderr, duration

DTOs remain **runner-agnostic**.

---

## Implement Cold Runner

```
packages/pestE2E/src/Workers/Playwright/PlaywrightWorker.php
```

Responsibilities:

* spawn Node runner (via `playwright test` CLI)
* pass environment variables
* capture stdout / stderr
* return canonical JSON report (via PlaywrightParser)

Bound to `JsWorkerContract` by default.

---

# [x] Phase 2 — Reduce Playwright Cold Start

Inside:

```
resources/js/pest-e2e/playwright/run.mjs
```

Goals:

* avoid unnecessary CLI overhead
* reduce Playwright bootstrap cost
* avoid scanning the entire repository

## Improvements

Pass explicit config path:

```
playwright test --config=resources/js/e2e/playwright.config.js
```

Benefits:

* skips auto discovery
* reduces filesystem scanning
* stabilizes startup

Expected improvement:

~0.5–1 second saved.

---

# [x] Phase 3 — Reuse Laravel Server

Currently each `->run()` boots a new Laravel server.

Instead reuse the server for the entire test suite.

## Modify

```
packages/pestE2E/src/Runners/ServerRunner.php
```

Add methods:

```
ServerRunner::getOrCreate()
ServerRunner::stopAll()
```

Behavior:

* first test boots server
* remaining tests reuse server
* shutdown occurs at PHPUnit suite end

Expected improvement:

~1 second saved per additional test.

---

# [x] Phase 4 — Add PlaywrightWarmRunner

Create:

```
packages/pestE2E/src/Runners/Js/PlaywrightWarmRunner.php
```

Warm runner maintains a persistent browser instance.

## Startup

```
playwright.launchServer()
```

Store returned `wsEndpoint`.

## Per test run

```
connect to browser
create new browser context
run test
close context
```

Isolation remains per test.

---

# Configuration

Add config option:

```php
'pest-e2e' => [
    'js_runner' => [
        'driver' => 'playwright',
        'mode' => 'cold'
    ]
]
```

Warm mode must remain **opt-in initially**.

---

# [x] Phase 5 — Warm Execution

Execution flow:

```
start BrowserServer
capture wsEndpoint
for each test:
    connect
    create context
    run test
    close context
```

Isolation maintained through context separation.

Expected performance:

First run: ~5–6s
Subsequent runs: ~2–3s

---

# [x] Phase 6 — Failure Fallback

Warm runner must never break the test suite.

If any of the following occur:

* BrowserServer fails to start
* connection fails
* wsEndpoint invalid
* Playwright crash

Then:

```
fallback to PlaywrightColdRunner
log warning
continue execution
```

---

# [x] Phase 7 — Validation

Verify that:

* canonical JSON report format remains unchanged
* public PHP API remains unchanged
* debug / browse mode still works
* browser processes always terminate
* no orphan processes remain

Run full benchmark suite again.

## Validation run (current)

- Canonical report schema remains `pest-e2e.v1`
- Public PHP API unchanged (`e2e(...)->run()/only()/runTest()` paths still green)
- Browse mode verified: `tests/Browser/UserProfileTest.php --browse` passes
- Debug mode verified: `tests/Browser/UserProfileTest.php --debug` passes
- Failure path verified: `tests/Unit/E2EFailuresTest.php` passes
- Process cleanup verified: no lingering `artisan serve`/Playwright warm runner processes after suite

## Benchmarks (UserProfile test)

- Single cold run: `7.70s` (E2E inner duration ~`711ms`)
- Single warm run: `7.82s` (E2E inner duration ~`712ms`)
- 5 sequential cold runs: `7.95s`, `7.71s`, `7.69s`, `7.39s`, `7.50s` (avg `7.65s`)
- 5 sequential warm runs: `8.02s`, `7.96s`, `7.57s`, `7.80s`, `7.57s` (avg `7.78s`)

Note: warm mode correctness is validated and fallback is in place, but benchmark gains are not yet visible in this scenario. Further warm-run optimization is still needed before switching defaults.

---

# Expected Performance

| Scenario            | Runtime |
| ------------------- | ------- |
| Current             | 7–13s   |
| Cold optimized      | ~5–6s   |
| Warm runner         | ~2–3s   |
| Actual browser test | ~1s     |

---

# Implementation Order

1. Phase 0 — Baseline
2. Phase 1 — JsWorkerContract
3. Phase 2 — Playwright startup optimization
4. Phase 3 — Laravel server reuse
5. Phase 4 — Warm runner
6. Phase 5 — Warm execution
7. Phase 6 — Fallback
8. Phase 7 — Validation

---

# Definition of Done

The feature is complete when:

* Cold mode behaves exactly like current implementation
* Warm runner exists and is opt-in
* Core architecture remains runner-agnostic
* Benchmarks show significant runtime improvement
* No package principles are violated
