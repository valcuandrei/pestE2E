# Pest E2E plugin – current state

## Goal
Build a Pest plugin that runs JS E2E runners (Playwright/Jest/etc),
ingests a JSON report, and fails Pest tests on JS failures.

## Architecture (locked)

- Laravel-first backend
- Package Service Provider (testing-only behavior)
- Pest used as orchestrator
- Explicit composition root
- JS runner logic lives entirely outside PHP

## Core pieces implemented
- TargetRegistry
- ProcessRunner (Symfony Process::fromShellCommandline)
- E2ERunner
  - Injected RunIdGeneratorContract
  - RandomRunIdGenerator (prod)
  - FixedRunIdGenerator (tests)
- JsonReportReader + Parser
- Deterministic E2E orchestrator test (no Node)

## Public API direction
- Global helper: e2e()
- e2e()->target('frontend', fn($p) => ...)
- e2e('frontend')->withEnv()->withParams()->run()

## Bootstrap
- pest-plugin.php defines the `e2e()` function
- No usage of src/Plugin.php yet (kept for future Pest contracts)

## Next tasks
1. Finalize single composition root (shared registry + runner factory)
2. Wire E2ETargetHandle::run() to shared runner
3. Add public API test for e2e() helper
4. Leave import() stubbed (reserved for future versions)

## Scope boundary

- Backend app: Laravel (explicit)
- Frontend runners: replaceable (Playwright, Jest, etc.)
- Browser logic never crosses into PHP
