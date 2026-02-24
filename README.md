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
* Runner agnostic (Playwright by default, others via `command()`)
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

### Unattended / CI mode

```bash
php artisan pest-e2e:install --yes
```

Alias:

```bash
php artisan pest-e2e:install --unattended
```

---

# Testing Environment (Important)

pestE2E starts a managed Laravel server using:

```
--env=testing
```

If a `.env.testing` file exists, Laravel automatically loads it.

You should create a `.env.testing` file with an isolated database configuration:

```dotenv
APP_ENV=testing
APP_DEBUG=true

DB_CONNECTION=mysql
DB_DATABASE=your_test_database

CACHE_STORE=file
SESSION_DRIVER=file

PEST_E2E_AUTH_ROUTE_ENABLED=true
```

This ensures:

* Your development database is not modified
* Auth routes are enabled only during testing
* The Pest process and the managed server use the same database

Your `phpunit.xml` database configuration must match `.env.testing` (or be removed) so both processes remain in sync.

The managed server is started in isolation and inherits no development state beyond explicitly provided environment variables.

---

# Quick Start

Configure a target inside the setUp() method of tests/E2ETestCase.php:

```php
e2e()->target('frontend', fn ($p) => $p
    ->dir('frontend')
    ->command('node resources/js/pest-e2e/playwright/run.mjs')
    ->report('json', 'storage/framework/testing/pest-e2e/{runId}/report.json')
);
```
>Targets should be registered once in your base E2E test case, not inside individual tests.

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
        ->actingAs($user)
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

---

# Debug & Headed Mode

```bash
php artisan test --browse
php artisan test --debug
```

* `--browse` / `--headed` → runs browser in headed mode
* `--debug` → enables debug mode and implies headed mode

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

Reports are written to:

```
storage/framework/testing/pest-e2e/{runId}
```

Configurable via:

```php
config('pest-e2e.reports.dir');
```

Schema: `pest-e2e.v1`

---

# Final Positioning

pestE2E is not browser testing for Laravel.

It is a **contract-driven bridge** between Laravel and JS-native E2E systems.

If you are building advanced frontend applications — page builders, editors, complex layouts — and you want Laravel to orchestrate while JavaScript owns the browser, this package is for you.
