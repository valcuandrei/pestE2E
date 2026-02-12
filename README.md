# pestE2E

Laravel-first backend, frontend-runner-agnostic bridge that runs **JS-owned** E2E/component tests (Playwright by default)
from Pest **without introducing a PHP browser DSL**. Supports test filtering for running specific tests.

## What this is

A **Laravel-first E2E orchestration layer** for Pest that runs
**JavaScript-owned** browser tests (Playwright by default)
and maps structured results back into Pest output.

Think of this as an **Inertia-style bridge for E2E testing**:

- Laravel owns test intent, state, auth, and data
- JavaScript owns browser execution
- A stable contract connects the two

Pest orchestrates JS test execution, passes context (env, params, auth),
and maps structured results back into Pest output.

## Key Features

- **JS Test Filtering**: Run specific tests with `e2e('frontend')->only('test name')` or `runTest('test name')`
- **Laravel Authentication**: Transfer auth state using `actingAs($user)` with one-time tickets
- **Runner Agnostic**: Supports Playwright, Jest, and other JS runners via configurable commands
- **Environment Aware**: Automatically inherits Laravel's execution environment (local, Docker, Sail)
- **Type Safe**: Full PHPStan level compliance with robust type checking

## Terminology
- Project: the Laravel app (backend)
- Target: a runnable E2E suite (frontend, admin, marketing, etc.)

## What this is NOT
- ❌ Not a browser abstraction
- ❌ Not a PHP wrapper around Playwright
- ❌ Not Dusk
- ❌ Not Selenium
- ❌ No `visit()`, `click()`, `type()` — ever

All browser logic lives in JS.

## Supported environments
- Laravel apps
- Pest
- Node-based test runners (Playwright first)
- Docker / Sail **supported but not required**

The execution environment is determined by **how Pest is invoked**
(e.g. `php artisan test` vs `./vendor/bin/sail artisan test`),
not by the package.

## Status
⚠️ Early design phase
The public API and architecture are being locked before implementation.

## Laravel integration
- Service provider is auto-discovered
- Testing-only routes load in the `testing` environment

### Test Environment Setup

For e2e tests to work properly with Laravel, configure your test environment:

**1. Enable the auth route in `.env.testing`:**
```bash
PEST_E2E_AUTH_ROUTE_ENABLED=true
APP_ENV=testing
DB_DATABASE=testing
CACHE_STORE=file
```

**2. Use `DatabaseMigrations` in tests:**
```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\DatabaseMigrations::class)
    ->in('Feature');
```

**3. Exclude pest-e2e routes from CSRF (`bootstrap/app.php`):**
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: [
        'pest-e2e/*',
    ]);
})
```

**4. Run a dev server with the testing environment:**
```bash
# When running tests, the web server needs to be in testing mode
APP_ENV=testing php artisan serve --host=0.0.0.0 --port=80
# Or with Sail:
sail exec -d laravel.test env APP_ENV=testing php artisan serve --host=0.0.0.0 --port=80
```

This ensures:
- The test process and web server share the same database and cache
- Auth tickets work across processes
- Playwright can authenticate and interact with your app

## Backend scope

This package is **Laravel-native by design**.

- Auth, sessions, Sanctum, and users are handled using Laravel primitives
- A testing-only auth endpoint is provided by the package
- Laravel DX (`actingAs`, personas) is a first-class goal

Runner-agnosticism applies to **targets**, not the Laravel app.

## Quick Start

```php
// Configure target with filtering support
e2e()->target('frontend', fn ($p) => $p
    ->dir('frontend')
    ->command('node resources/js/pest-e2e/playwright/run.mjs')
    ->filter('--grep') // Enable test filtering
    ->report('json', '.pest-e2e/{runId}/report.json')
);

// Run all tests
e2e('frontend')->run();

// Run specific test
e2e('frontend')->only('can login')->run();

// Run specific test with authentication  
e2e('frontend')
    ->actingAs($user)
    ->runTest('can checkout');
```

## JavaScript Integration

This package provides a TypeScript/JavaScript harness that can be published into your Laravel app for use with Playwright and other test runners.

### Publishing the JavaScript Assets

Publish the JavaScript harness files to your Laravel app:

```bash
php artisan pest-e2e:publish
```

This publishes the JavaScript files to `resources/js/pest-e2e/`:
- `core.mjs` - Core utilities for reading parameters and authentication
- `playwright.mjs` - Playwright-specific global setup and helpers
- `playwright/run.mjs` - Playwright runner wrapper with report conversion
- `playwright/convert.mjs` - Playwright JSON report converter

### Playwright Integration

#### 1. Configure Your Target Command

Configure your Laravel target to use the Playwright runner wrapper:

```php
e2e()->target('frontend', fn ($p) => $p
    ->dir('frontend')
    ->command('node resources/js/pest-e2e/playwright/run.mjs')
    ->report('json', '.pest-e2e/{runId}/report.json')
);
```

The runner wrapper (`run.mjs`) automatically:
- Executes Playwright with JSON reporting
- Converts the raw Playwright report to PestE2E canonical format
- Writes the canonical report to the expected path for PHP consumption

#### 2. Configure Global Setup

In your `playwright.config.js`:

```javascript
import { storageStatePath } from '../pest-e2e/playwright.mjs';

export default {
  globalSetup: './global-setup.mjs', // See below
  use: {
    baseURL: process.env.APP_URL || 'http://localhost',
    storageState: storageStatePath(), // Auto-auth for all tests
  },
  // ... other config
};
```

Create `global-setup.mjs` in your test directory:

```javascript
export { globalSetup as default } from '../pest-e2e/playwright.mjs';
```

The `globalSetup` automatically:
- Reads the auth ticket from `PEST_E2E_PARAMS`
- Calls the pest-e2e auth endpoint (`/pest-e2e/auth/login`)
- Saves session cookies to `storageState.json`
- All tests automatically use the authenticated session

#### 3. Write Clean Tests

Your Playwright tests are now automatically authenticated. No boilerplate needed:

```typescript
import { test, expect } from '@playwright/test';

test('UserProfile can update their profile', async ({ page }) => {
    await page.goto('/settings/profile');
    await page.locator('#name').fill('Test User');
    await page.locator('#email').fill('test@example.com');
    await page.getByTestId('update-profile-button').click();
    await expect(page.getByText('Saved.')).toBeVisible();
});
```

The `globalSetup` handles all auth automatically using the ticket from `actingAs($user)`.

#### 3. Report Conversion

The Playwright runner wrapper automatically converts Playwright's JSON report format to PestE2E's canonical JSON schema (v1). This ensures PHP can always read a stable report format regardless of Playwright version changes.

**Canonical Report Schema:**
```json
{
  "schema": "pest-e2e.v1",
  "target": "string",
  "runId": "string", 
  "stats": {
    "passed": 0,
    "failed": 0,
    "skipped": 0,
    "durationMs": 0
  },
  "tests": [
    {
      "name": "test name",
      "status": "passed|failed|skipped",
      "durationMs": 1234,
      "error": {
        "message": "error details (if failed)"
      }
    }
  ]
}
```

**Status Mapping:**
- `passed` → `passed`
- `skipped` → `skipped`  
- `failed`/`timedOut`/`interrupted` → `failed`

**Test Naming:**
- Uses `titlePath` joined with " › " when available
- Falls back to `title` 
- Prefixes with `[project]` when multiple Playwright projects are configured

#### 4. Environment Variables

The harness reads configuration from these environment variables:

- `PEST_E2E_PARAMS` - JSON string containing parameters
- `PEST_E2E_PARAMS_FILE` - Path to JSON file with parameters (fallback)
- `APP_URL` - Your Laravel application URL
- `PEST_E2E_RUN_ID` - Unique run identifier (auto-generated if not set)
- `PEST_E2E_TARGET` - Target name (set automatically by Laravel)
- `PEST_E2E_REPORT_PATH` - Path for canonical report (defaults to `.pest-e2e/{runId}/report.json`)

### Core API

The published `core.mjs` provides these utilities:

```javascript
import {
  readParams,
  getAppUrl,
  hasAuthTicket,
  getAuthTicket
} from '../pest-e2e/core.mjs';

// Read parameters from environment
const params = await readParams();

// Get application URL
const appUrl = getAppUrl(params); // Falls back to APP_URL env var

// Check for authentication ticket (handled automatically by globalSetup)
if (hasAuthTicket(params)) {
  const ticket = getAuthTicket(params);
  // No need to use this manually - globalSetup handles auth automatically
}
```

**Note:** With `globalSetup` configured, you don't need to manually handle auth in your tests.

### Custom Integration

If you're not using Playwright, you can import the core utilities directly:

```javascript
import { readParams, getAppUrl } from '../pest-e2e/core.mjs';

// Your custom test runner setup
async function setupTests() {
  const params = await readParams();
  const appUrl = getAppUrl(params);
  
  // Use params and appUrl in your test setup
}
```
