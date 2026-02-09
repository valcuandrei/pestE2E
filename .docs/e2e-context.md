# Pest E2E package – current state

## Goal
Build a Laravel package that runs JS E2E runners (Playwright/Jest/etc),
ingests a JSON report, and fails Pest tests on JS failures.

## Architecture (locked)

- Laravel-first backend
- Package Service Provider (testing-only behavior)
- Pest used as orchestrator
- Container-managed composition root
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
- PestE2EServiceProvider registers bindings and testing routes
- pest-plugin.php defines the `e2e()` helper

## Next tasks
1. Add package docs for Laravel installation and config
2. Expand auth route validation and payload support

## Recently Implemented
- **Test Filtering**: Added `only()` and `runTest()` methods for running specific JS tests
- **Target Configuration**: Added `filter()` method to target builder for configuring runner-specific filter flags
- **Error Handling**: Comprehensive validation when filtering is requested but not configured

## Scope boundary

- Backend app: Laravel (explicit)
- Frontend runners: replaceable (Playwright, Jest, etc.)
- Browser logic never crosses into PHP
