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
    ->command('npx playwright test')
    ->filter('--grep') // Enable test filtering
    ->report('json', 'test-results/report.json')
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
