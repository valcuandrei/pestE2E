# API — Public Surface (v1)

Anything here is stable once implemented.

## Entry point
- `e2e()`
- `e2e('target')`

## Target definition
```php
e2e()->target('frontend', fn ($p) => $p
    ->dir('js')
    ->runner('playwright') // informational only
    ->command('npx playwright test') // executed in the same environment as Pest
    ->report('json', 'storage/e2e/frontend/report.json')
    ->env(['APP_URL' => 'http://localhost'])
    ->params(['baseUrl' => 'http://localhost'])
);
```

### Important
- `command()` MUST NOT include `sail`, `docker`, or similar wrappers
- The command runs wherever Pest is executed

## Runtime overrides
- `withEnv(array)`
- `withParams(array)`

## Execution
- `run()` — run suite, fail on JS failures
- `import()` — import JS tests as Pest tests
- `call(file, export?, params?)` — run standalone JS export

> Note: `import()` is reserved for future versions and is not part of the v1 execution path.

Shorthand:
- `call('js/tasks/seed.ts:seedDatabase', [...])`

### Authentication

When using Laravel, tests may authenticate E2E runs via
`actingAs()` or personas.

Authentication state is transferred to JS using a
one-time auth ticket and a testing-only login endpoint.

Example:
```php
e2e('frontend')->actingAs($user, [
    'guard' => 'web',
    'mode' => 'session',
    'meta' => ['tenant' => 'acme'],
]);
```

The JS runner should POST the ticket to
`/.well-known/pest-e2e/auth/login` (configurable).
