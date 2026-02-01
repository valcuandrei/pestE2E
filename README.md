# pestE2E

A Pest plugin that runs **JS-owned** E2E/component tests (Playwright by default)
from Pest **without introducing a PHP browser DSL**.

## What this is
- A **test orchestrator**
- A **process runner**
- A **result ingestor**
- A **bridge between Pest and Node**

Pest executes JS tests, passes context (env, params, auth), and maps structured
results back into Pest output.

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
