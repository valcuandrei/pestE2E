# pestE2E

- pestE2E runs **JavaScript-owned** browser tests (Playwright by default) from **Pest**. Laravel supplies intent, data, and auth; the JS runner owns the browser. **Never** introduce a PHP browser DSL (`visit()`, `click()`, `fill()`, etc.) for E2E flows handled by this package.
- Register `e2e()->target(...)` in the **base E2E test case** `setUp()` (for example `tests/E2ETestCase.php`), not inside individual test bodies or static one-time blocks — the container is refreshed between tests and targets must be re-registered each time.
- **Do not** run suites that invoke `e2e()->…->run()` with Pest **`--parallel`**, PHPUnit process splitting, or any model where multiple PHP processes each start their own E2E run against the same app. E2E is **sequential-only** (managed app server, tickets, testing env).
- Use `search-docs` for Laravel, Pest, PHPUnit, and Playwright topics. For package-specific contracts and the public PHP API, read **`CONTRACTS.md`** and **`API.md`** inside the installed package (`.docs/` directory relative to the package root).
- **IMPORTANT:** Activate the **`pest-e2e-development`** skill when adding or changing E2E tests, Playwright specs, `e2e()` targets, `actingAs()` / auth tickets, CSRF exclusions for the auth route, `config/pest-e2e.php`, or files under `resources/js/pest-e2e/`.

## Auth and safety

- The testing-only login route defaults to **`/pest-e2e/auth/login`** (see `config('pest-e2e.auth.route')`). Enable with **`PEST_E2E_AUTH_ROUTE_ENABLED=true`** in `.env.testing` (not production).
- On Herd/Windows (and similar), the auth POST may require CSRF exception for that path — the installer can add it to `bootstrap/app.php`.
- Browser tests receive auth via **`params.auth`** (ticket, mode, guard, meta); JS should POST to the configured route with the package header (`config('pest-e2e.auth.header')`) when required.

## Installer

- **`php artisan pest-e2e:install`** publishes config, base test case, JS harness, Playwright wiring, and optional env/phpunit fixes. Prefer established publish tags over copying stubs by hand.
