# pestE2E

Laravel-first backend, frontend-runner-agnostic bridge that runs **JS-owned** E2E/component tests (Playwright by default)
from Pest **without introducing a PHP browser DSL**.

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
not by the plugin.

## Status
⚠️ Early design phase
The public API and architecture are being locked before implementation.

## Backend scope

This package is **Laravel-native by design**.

- Auth, sessions, Sanctum, and users are handled using Laravel primitives
- A testing-only auth endpoint is provided by the package
- Laravel DX (`actingAs`, personas) is a first-class goal

Runner-agnosticism applies to **targets**, not the Laravel app.
