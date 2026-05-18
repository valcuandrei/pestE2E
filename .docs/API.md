# API — Public Surface (v1)

Anything here is stable once implemented.

## Entry point
- `e2e()`
- `e2e('target')`

## Target definition

```php
e2e()->target('frontend', fn ($p) => $p
    ->dir('resources/js/e2e')
    ->env(['APP_URL' => 'http://localhost'])
    ->params(['baseUrl' => 'http://localhost'])
);
```

## Runtime overrides
- `withEnv(array $env)`
- `withParams(array $params)`

## Execution
- `run()` — run suite, fail on JS failures
- `only(string $pattern)` — run a subset when the runner supports filtering (runner-specific)
- `runTest(string $name)` — convenience alias for single-test execution

**Parallel runs:** Supported via Pest / Laravel `--parallel`. Each worker gets a dedicated port (`parallel.base_port` + `TEST_TOKEN`), worker-scoped auth tickets, and `APP_URL` passed to Playwright. Requires per-worker databases (`{database}_test_{TEST_TOKEN}`) using Laravel’s parallel testing traits.

**Output noise control:** Normal runs print E2E details for passed and failed targets. `--compact` and `--parallel` suppress passed E2E details, but failed E2E details are always printed.

**Agent / PAO output:** When running inside an AI-agent environment (detected via `laravel/agent-detector` when installed, or common agent env vars such as `CURSOR_AGENT`), Pest E2E emits one compact JSON line per E2E run instead of verbose terminal output.

Ways to enable:

- CLI: `php artisan test --pest-e2e-agent-output` (or `--pest-e2e-json`)
- Env: `PEST_E2E_AGENT_OUTPUT=1` or `PAO_FORCE=1` (with Laravel Sail, add these to `.env.testing` or ensure `compose.yaml` forwards host env vars)
- Config: `config('pest-e2e.agent_output')` from `.env` / `.env.testing`

Disable with `PEST_E2E_AGENT_OUTPUT_DISABLE=1` or `PAO_DISABLE=1`.

In agent mode, Pest/Collision human-readable output is suppressed; only compact JSON lines are printed (one per E2E run, at the end of the test suite). With `--parallel`, workers write summaries to a shared directory and the coordinator prints them after all workers finish.

When `php artisan test` is used, agent env vars are persisted before Pest is spawned (Laravel replaces the subprocess environment in parallel mode). Pest workers hydrate them from `storage/framework/testing/pest-e2e-agent-output/.agent-intent.json`.

#### Agent JSON shape

Every E2E run emits one line:

```json
{
  "target": "frontend",
  "result": "passed",
  "passed": 1,
  "failed": 0,
  "duration_ms": 623,
  "report_dir": "/path/to/storage/framework/testing/pest-e2e/frontend/run-id"
}
```

Failed runs add context:

| Field | Description |
| ----- | ----------- |
| `php_test.file` | Pest test file (project-relative) |
| `php_test.name` | Pest test description |
| `failures[].name` | Playwright test title |
| `failures[].js_file` | Playwright spec path (project-relative) |
| `failures[].message` | Error message from the runner report |
| `failures[].stack` | Stack trace from the runner report |
| `error.message` / `error.stack` | PHP/runtime failure when no Playwright failure rows were parsed |

### Filtering (runner-specific)

```php
e2e('frontend')->only('UserProfile')->run();
e2e('frontend')->runTest('UserProfile can update their profile');
```

If the configured runner does not support filtering, `only()`/`runTest()` throws a descriptive `RuntimeException`.

## Playwright timeouts

Published `playwright.config.js` uses a **5 second** default (test, expect, action, navigation). When debugging failures, fix selectors, redirects, and URLs — **do not** increase Playwright timeouts.

## Debugging
- `browse()` — headed execution (alias of `--browse`)
- `debug()` — enables debug mode (implies browse/headed)

CLI flags:
- `--browse` (alias: `--headed`)
- `--debug`
- `--run-using=npm|yarn|pnpm|bun` — use a specific package manager for E2E runs (overrides config and E2ETestCase default)

## Authentication

When using Laravel, tests may authenticate E2E runs via
`actingAs()` or `loginAs()` (alias) or personas.

Authentication state is transferred to JS using a
one-time auth ticket and a testing-only login endpoint.

```php
e2e('frontend')->actingAs($user, [
    'guard' => 'web',
    'mode' => 'session',
    'meta' => ['tenant' => 'acme'],
]);
```

The JS runner should POST the ticket to the configured auth route:

- default: `/pest-e2e/auth/login`
- configurable: `config('pest-e2e.auth.route')`

## Report handling

Reports are parsed via `JsonParserContract` (default: `PlaywrightParser`).
The JS runner emits JSON to stdout; the PHP side parses it in memory.
Swap the parser by rebinding `JsonParserContract` in `config('pest-e2e.bindings')`.

Runner artifacts are written to `config('pest-e2e.reports.base_dir')/{target}/{runId}`.

Pruning is controlled by `config('pest-e2e.reports.prune')`. The current run directory is never deleted.
