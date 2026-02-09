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
    ->filter('--grep') // (optional) flag for test filtering
    ->report('json', 'storage/e2e/frontend/report.json')
    ->env(['APP_URL' => 'http://localhost'])
    ->params(['baseUrl' => 'http://localhost'])
);
```

### Target Configuration Options
- `filter(string $flag)` — _(optional)_ Configure the CLI flag used for filtering tests (e.g., `--grep` for Playwright, `--testNamePattern` for Jest)

### Important
- `command()` MUST NOT include `sail`, `docker`, or similar wrappers
- The command runs wherever Pest is executed

## Runtime overrides
- `withEnv(array)`
- `withParams(array)`

## Execution
- `run()` — run full test suite, fail on JS failures
- `only(string $testName)` — set test filter, returns chainable handle
- `runTest(string $testName)` — convenience method, equivalent to `only($testName)->run()`
- `call(file, export?, params?)` — run standalone JS export

### Test Filtering
Filter specific tests using runner-specific patterns:

```php
// Run specific test
e2e('frontend')->only('can checkout')->run();

// Convenience alias (runs immediately)
e2e('frontend')->runTest('can checkout');

// Chainable with other methods
e2e('frontend')
    ->actingAs($user)
    ->withParams(['orderId' => 123])
    ->only('checkout flow')
    ->run();
```

**Requirements:**
- Target must be configured with `->filter('--flag')` to support filtering
- Filter patterns are passed directly to the JS runner (e.g., `npx playwright test --grep 'test name'`)
- Throws descriptive `RuntimeException` if `only()` or `runTest()` is called but filtering is not configured for the target
- Filter flag and pattern are shell-escaped for security

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

## Complete Example

```php
// Target configuration with filtering support
e2e()->target('frontend', fn ($p) => $p
    ->dir('frontend')
    ->runner('playwright')
    ->command('npx playwright test')
    ->filter('--grep') // Enable test filtering
    ->report('json', 'test-results/report.json')
    ->env(['APP_URL' => config('app.url')])
);

// Basic E2E test
it('[e2e:frontend] can view homepage', function () {
    e2e('frontend')->run();
});

// Filtered E2E test with authentication
it('[e2e:frontend] can checkout as user', function () {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->create(['status' => 'draft']);

    e2e('frontend')
        ->actingAs($user)
        ->withParams(['orderId' => $order->id])
        ->only('can complete checkout'); // Run only this specific test

    expect(Order::whereKey($order->id)->value('status'))->toBe('completed');
});

// Convenience method for single test execution
it('[e2e:frontend] can login', function () {
    $user = User::factory()->create();
    
    e2e('frontend')->runTest('can login with valid credentials');
    
    // Equivalent to: e2e('frontend')->only('can login with valid credentials')->run()
});
```
