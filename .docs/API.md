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
### Filtering (runner-specific)

```php
e2e('frontend')->only('UserProfile')->run();
e2e('frontend')->runTest('UserProfile can update their profile');
```

If the configured runner does not support filtering, `only()`/`runTest()` throws a descriptive `RuntimeException`.

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
The JS runner emits JSON to stdout; the PHP side parses it in memory — no disk storage.
Swap the parser by rebinding `JsonParserContract` in `config('pest-e2e.bindings')`.
