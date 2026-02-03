# Pest E2E plugin – current state

## Goal
Build a Pest plugin that runs JS E2E runners (Playwright/Jest/etc),
ingests a JSON report, and fails Pest tests on JS failures.

## Architecture (locked)
- No Laravel container
- No service providers
- Explicit composition root
- Pest-native bootstrap via `pest-plugin.php`

## Core pieces implemented
- ProjectRegistry
- ProcessRunner (Symfony Process::fromShellCommandline)
- E2ERunner
  - Injected RunIdGeneratorContract
  - RandomRunIdGenerator (prod)
  - FixedRunIdGenerator (tests)
- JsonReportReader + Parser
- Deterministic E2E orchestrator test (no Node)

## Public API direction
- Global helper: e2e()
- e2e()->project('frontend', fn($p) => ...)
- e2e('frontend')->withEnv()->withParams()->run()

## Bootstrap
- pest-plugin.php defines the `e2e()` function
- No usage of src/Plugin.php yet (kept for future Pest contracts)

## Next tasks
1. Finalize single composition root (shared registry + runner factory)
2. Wire E2EProjectHandle::run() to shared runner
3. Add public API test for e2e() helper
4. Leave import() and call() stubbed
