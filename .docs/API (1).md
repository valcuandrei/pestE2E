# API — Public Surface (v1)

Anything here is stable once implemented.

## Entry point
- `e2e()`
- `e2e('project')`

## Project definition
```php
e2e()->project('frontend', fn ($p) => $p
    ->dir('js')
    ->runner('playwright') // informational only
    ->command('npx playwright test') // executed in the same environment as Pest
    ->report('junit', 'storage/e2e/frontend/junit.xml')
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

Shorthand:
- `call('js/tasks/seed.ts:seedDatabase', [...])`
