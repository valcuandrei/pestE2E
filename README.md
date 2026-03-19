# pestE2E

**Laravel-first E2E orchestration for JavaScript-native browser testing.**

Run your existing JS E2E suite (Playwright by default) from Pest — without introducing a PHP browser DSL.

---

## What This Is

**pestE2E** is a Laravel-native orchestration layer that runs JavaScript-owned browser tests and maps structured results back into Pest output.

Conceptually, this is an Inertia-style bridge for E2E testing:

* Laravel owns test intent, state, authentication, and data
* JavaScript owns browser execution
* A stable contract connects the two

Pest orchestrates JS execution, passes context (environment, params, auth), and consumes structured JSON reports.

This package does **not** wrap Playwright in PHP.
It orchestrates your existing JS test suite from Laravel.

---

## Key Features

* JS test filtering via `only()` and `runTest()`
* Laravel authentication using one-time auth tickets
* Runner agnostic (Playwright by default, extensible via worker contracts)
* Managed testing server (no manual `php artisan serve`)
* Isolated testing environment
* Stable JSON reporting contract (`pest-e2e.v1`)
* Fully type-safe (PHPStan compliant)

---

## What This Is NOT

* Not a browser abstraction
* Not a PHP wrapper around Playwright
* Not Dusk
* Not Selenium
* No `visit()`, `click()`, or `type()` in PHP — ever

All browser logic lives in JavaScript.

---

## Status

**Stable v1**

The public PHP API, authentication contract, and JSON report schema are locked.
Internal runner adapters may evolve.

### ⚠️ Parallel test execution is not supported

**Do not use Pest’s `--parallel` option (or other parallel PHPUnit / process splitting) for suites that call `e2e()->…->run()`.** E2E tests must run **one process at a time**: they rely on a managed app server, auth tickets, shared testing DB/session configuration, and coordinated Playwright processes. Running Browser/E2E tests in parallel is **unsupported** and may fail unpredictably (lost targets, port conflicts, flaky auth). Run those tests **sequentially** (default for a single `pest` / `phpunit` process without `--parallel`). Parallel support may be added in a future release.

---

# Installation

Install the package:

```bash
composer require valcuandrei/pest-e2e --dev
```

Then run:

```bash
php artisan pest-e2e:install
```

The installer can:

* Update your `pest.php` to include `E2ETestCase`
* Publish `config/pest-e2e.php`
* Publish the base E2E test case
* Publish the JS harness
* Publish the Playwright integration
* Install Playwright
* Create `.env.testing` from `.env` with E2E-appropriate overrides
* Create `database/testing.sqlite` for SQLite tests
* Configure `phpunit.xml` to let `.env.testing` control DB/cache (comment out overrides)
* Ensure `phpunit.xml` defines a **Browser** testsuite for `tests/Browser` (when `phpunit.xml` exists; idempotent on every successful install)

Each step is skipped if already done. Use explicit flags to force: `--setup-env-testing`, `--setup-testing-database`, `--configure-phpunit`.

When publishing `E2ETestCase`, the installer sets the default JS package manager from tools **found on your PATH** (`pnpm`, `yarn`, `bun`, `npm`). Pass **`--package-manager=pnpm`** (etc.) to force the stub value and skip detection. If more than one is available, you are prompted to pick one in interactive installs; with `--no-interaction` / `--yes`, it prefers a manager that matches an existing **lockfile**, otherwise the first in priority order (pnpm → yarn → bun → npm). If none are on PATH, it falls back to lockfile-only detection (same order), then `npm`.

### Unattended / CI mode

```bash
php artisan pest-e2e:install --yes
```

Alias:

```bash
php artisan pest-e2e:install --unattended
```

### Options

| Option | Description |
|--------|-------------|
| `--yes` | Answer yes to all questions (performs full setup: update-pest, publish-config, publish-base-test-case, publish-js-harness, publish-js-playwright, add-csrf-exclusion, setup-env-testing, setup-testing-database, configure-phpunit, install-playwright) |
| `--no` | Answer no to all questions |
| `--force` | Overwrite existing files when publishing |
| `--update-pest` | Update Pest config to include E2ETestCase |
| `--setup-env-testing` | Create `.env.testing` from `.env` with E2E overrides |
| `--setup-testing-database` | Create `database/testing.sqlite` |
| `--configure-phpunit` | Comment out DB/cache env in `phpunit.xml` so `.env.testing` controls them |
| `--add-csrf-exclusion` | Add pest-e2e auth route to CSRF exclusion (required for Herd/Windows) |
| `--publish-config` | Publish config |
| `--publish-base-test-case` | Publish E2ETestCase |
| `--publish-js-harness` | Publish JS harness |
| `--publish-js-playwright` | Publish Playwright adapter |
| `--publish-browser-tests` | Publish browser tests |
| `--publish-playwright-tests` | Publish Playwright tests |
| `--install-playwright` | Install Playwright via npm |
| `--package-manager=` | Force the value embedded in `E2ETestCase` (`npm`, `yarn`, `pnpm`, `bun`); skips PATH / lockfile detection and interactive choice |

---

# Testing Environment (Important)

pestE2E starts a managed Laravel server using:

```
--env=testing
```

If a `.env.testing` file exists, Laravel automatically loads it.

**The installer can create this for you** with `--setup-env-testing` (included in `--yes`). It copies `.env` and applies E2E overrides:

```dotenv
APP_ENV=testing
APP_URL=http://127.0.0.1

DB_CONNECTION=sqlite
DB_DATABASE=testing

CACHE_STORE=database
SESSION_DRIVER=database

PEST_E2E_AUTH_ROUTE_ENABLED=true
```

For SQLite, the installer can also create `database/testing.sqlite` (`--setup-testing-database`) and configure `phpunit.xml` to omit DB/cache env vars so `.env.testing` controls them (`--configure-phpunit`).

**Manual setup:** If you prefer to configure yourself, create `.env.testing` with an isolated database:

```dotenv
APP_ENV=testing
APP_DEBUG=true

DB_CONNECTION=sqlite
DB_DATABASE=testing

CACHE_STORE=database
SESSION_DRIVER=database

PEST_E2E_AUTH_ROUTE_ENABLED=true
```

This ensures:

* Your development database is not modified
* Auth routes are enabled only during testing
* The Pest process and the managed server use the same database
* Cache and session are shared (required for auth ticket exchange)

Your `phpunit.xml` must not override `DB_CONNECTION`, `DB_DATABASE`, `CACHE_STORE`, or `SESSION_DRIVER` — let `.env.testing` control them. The installer can comment these out for you with `--configure-phpunit`.

The managed server is started in isolation and inherits no development state beyond explicitly provided environment variables.

---

# Quick Start

Configure a target inside the setUp() method of tests/E2ETestCase.php:

```php
e2e()->target('frontend', fn ($p) => $p
    ->dir('resources/js/e2e')
    ->env(['APP_URL' => 'http://localhost'])
    ->params(['baseUrl' => 'http://localhost'])
);
```
>Register targets in your base E2E test case (`E2ETestCase::setUp()`), not inside individual test functions. Do not run those tests with `--parallel` (see **Parallel test execution is not supported** under [Status](#status) above).

Run all tests:

```php
e2e('frontend')->run();
```

Run a specific test:

```php
e2e('frontend')->runTest('UserProfile can update their profile');
```

---

# Example: Complex Frontend Flow

## PHP (Pest)

```php
use App\Models\User;

test('that a user can update their profile', function () {
    $user = User::factory()->create();

    e2e('frontend')
        ->actingAs($user)  // or ->loginAs($user)
        ->withParams([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])
        ->runTest('UserProfile can update their profile');

    expect($user->fresh()->name)->toBe('Test User');
    expect($user->fresh()->email)->toBe('test@example.com');
});
```

## JavaScript (Playwright)

```ts
import { test, expect } from '@playwright/test';
import { readParams } from '../pest-e2e/core.mjs';

test('UserProfile can update their profile', async ({ page }) => {
    const { name, email } = await readParams();

    await page.goto('/settings/profile');
    await page.locator('#name').fill(name);
    await page.locator('#email').fill(email);
    await page.getByTestId('update-profile-button').click();

    await expect(page.getByText('Saved.')).toBeVisible();
});
```

Laravel controls state and authentication.
JavaScript controls the browser.

---

# Why Not Use Pest’s Native Browser Testing?

Pest’s built-in browser testing (Dusk-style) is excellent for:

* Form submissions
* CRUD flows
* Traditional backend-driven pages
* Simple UI assertions

However, for advanced frontend systems such as:

* Drag-and-drop page builders
* Resizable layout systems
* CSS box-model assertions (width, height, margin, padding)
* Transform-based positioning
* Vue / Pinia state inspection
* DOM measurement and layout calculations

You need full native Playwright running in its own JavaScript environment.

pestE2E orchestrates your JS suite — it does not abstract it.

---

# Managed Testing Server

When you call:

```php
e2e('frontend')->run();
```

pestE2E automatically:

1. Boots a temporary Laravel HTTP server
2. Forces it into `APP_ENV=testing`
3. Binds it to `127.0.0.1` on a free port
4. Executes your JS runner against that server
5. Collects the JSON report
6. Shuts the server down

No manual `php artisan serve` required.
No environment leakage into development.

---

# Running Tests

Local:

```bash
php artisan test
```

Sail:

```bash
sail artisan test
```

**E2E / Browser tests:** keep the default **sequential** run. Avoid `pest --parallel` / `php artisan test --parallel` for directories or projects that include `e2e()->…->run()` — parallel execution is **not supported** at this time (see warning under [Status](#status)).

---

# Debug & Headed Mode

```bash
php artisan test --browse
php artisan test --debug
php artisan test --run-using=yarn
```

* `--browse` / `--headed` → runs browser in headed mode
* `--debug` → enables debug mode and implies headed mode
* `--run-using=npm|yarn|pnpm|bun` → use a specific package manager for E2E runs (default is set in `E2ETestCase::$e2ePackageManager` during install)

## Timing Instrumentation

Enable baseline timing markers:

```dotenv
PEST_E2E_TIMING=true
```

Markers are emitted to `stderr` with prefix:

```text
[pest-e2e:timing]
```

Each marker is JSON payload with `phase`, `atMs`, and optional `durationMs`.

## Headed Mode in Sail (WSL2 + WSLg)

If you run Pest inside Sail on Windows (WSL2) and want headed mode, forward WSLg into the container by adding this to your `laravel.test` service:

```yaml
environment:
  DISPLAY: ${DISPLAY}
  WAYLAND_DISPLAY: ${WAYLAND_DISPLAY}
  XDG_RUNTIME_DIR: ${XDG_RUNTIME_DIR}
  PULSE_SERVER: ${PULSE_SERVER}

volumes:
  - /mnt/wslg:/mnt/wslg
  - /tmp/.X11-unix:/tmp/.X11-unix
```

This is only required for headed browser mode inside Docker on WSL2.
Headless mode works without additional configuration.

---

# Authentication Contract

Default auth route:

```
/pest-e2e/auth/login
```

Configurable via:

```php
config('pest-e2e.auth.route');
```

Security:

* Disabled by default
* Requires header (default: `X-Pest-E2E: 1`)
* Tickets are single-use and short-lived

---

# Reports

The package does **not** store JSON reports on disk. Playwright emits its JSON report to stdout; the PHP side parses it in memory and maps it to the canonical `pest-e2e.v1` schema.

---

# Configuration

Key config keys in `config/pest-e2e.php`:

| Key | Description |
|-----|-------------|
| `auth.route` | Auth endpoint path (default: `/pest-e2e/auth/login`) |
| `auth.route_enabled` | Enable auth route (default: `false`, set via `PEST_E2E_AUTH_ROUTE_ENABLED`) |
| `auth.ttl_seconds` | Auth ticket TTL (default: 60) |
| `auth.header.name` / `auth.header.value` | Header required for auth requests (default: `X-Pest-E2E: 1`) |
| `server.driver` | Server runner: `artisan` or `php_builtin` (default: `php_builtin`) |
| `timing.enabled` | Enable timing instrumentation (default: `false`, set via `PEST_E2E_TIMING`) |
| `js_runner.driver` | JS runner (default: `playwright`) |
| `js_runner.mode` | Runner mode: `cold` or `warm` (default: `cold`) |
| `package_manager` | Package manager for E2E runs: `npm`, `yarn`, `pnpm`, or `bun` (default: set in E2ETestCase during install, overridable via `--run-using`) |
| `bindings` | Contract-to-implementation map for swapping the JS runner. Keys: `JsWorkerContract::class`, `JsonParserContract::class`. Default: Playwright. Override to use Cypress, Puppeteer, etc. |

---

# Final Positioning

pestE2E is not browser testing for Laravel.

It is a **contract-driven bridge** between Laravel and JS-native E2E systems.

If you are building advanced frontend applications — page builders, editors, complex layouts — and you want Laravel to orchestrate while JavaScript owns the browser, this package is for you.
