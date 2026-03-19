---
name: pest-e2e-development
description: Develops and maintains pestE2E (valcuandrei/pest-e2e) JavaScript-driven E2E tests orchestrated from Pest. Activates when working with e2e() targets, E2ETestCase, Playwright specs, resources/js/pest-e2e harness, pest-e2e auth tickets, config/pest-e2e.php, pest-e2e:install, or when the user mentions browser E2E from Pest, pestE2E, or JS-owned E2E.
---

# pestE2E development

Laravel-first orchestration: **Pest calls into Node**; **Playwright (or another bound runner) executes** the suite. Structured JSON reports return to PHP.

## Mental model

- **PHP:** targets, env overrides, `withParams()`, `actingAs()` / `loginAs()`, `run()` / `only()` / `runTest()`, `browse()` / `debug()`.
- **JavaScript:** all DOM interaction, navigation, assertions. Use **`readParams()`** from the published harness (`resources/js/pest-e2e/core.mjs`) to read data and auth from the bridge.
- **Not in scope:** re-implementing browser steps in PHP.

## Where things live

| Area | Typical location |
|------|------------------|
| Base test case | `tests/E2ETestCase.php` (published stub) |
| Targets | Inside `E2ETestCase::setUp()` via `e2e()->target('name', fn ($p) => $p->dir(...)->env([...])->params([...]))` |
| Playwright tests | Directory passed to `->dir()` (default stub: `resources/js/e2e`) |
| Harness | `resources/js/pest-e2e/core.mjs`, `playwright.mjs` |
| Config | `config/pest-e2e.php` |
| Contracts / API | Package `.docs/CONTRACTS.md`, `.docs/API.md` |

## Environment injected into the JS process

- `PEST_E2E_TARGET`, `PEST_E2E_RUN_ID`
- `PEST_E2E_PARAMS` (JSON) and/or `PEST_E2E_PARAMS_FILE` (absolute path)

Use `readParams()` so you handle both inline and file-based payloads.

## Authentication flow

1. In PHP: `e2e('frontend')->actingAs($user, ['guard' => 'web', 'mode' => 'session', 'meta' => [...]])` (or `loginAs`).
2. Package issues a **one-time ticket**; JS sees `params.auth` with `ticket`, `mode`, `guard`, `meta`.
3. JS POSTs to **`/pest-e2e/auth/login`** by default (`config('pest-e2e.auth.route')`), with testing-only routing gated by **`PEST_E2E_AUTH_ROUTE_ENABLED`** and the configured header.
4. Implement **`E2EAuthActionContract`** in the app if you customize server/auth behavior.

## Configuration reference

Key `config/pest-e2e.php` sections:

- **`bindings`** — `JsWorkerContract` and `JsonParserContract` (default Playwright worker + parser).
- **`auth`** — route, TTL, header, `route_enabled`.
- **`server`**, **`js_runner`**, **`package_manager`**, **`timing`** — see published config file for env keys.

CLI overrides: **`--browse`** / **`--headed`**, **`--debug`**, **`--run-using=npm|yarn|pnpm|bun`**.

## Parallelism

**Unsupported:** Do not use `pest --parallel` (or equivalent) for tests that call `e2e()->…->run()`. Run E2E sequentially in a single process.

## Playwright snippet (params + auth)

Use patterns aligned with published stubs: read params, resolve `APP_URL` / `baseUrl`, call auth endpoint when `hasAuthTicket(params)` before `page.goto`.

```ts
import { test, expect } from '@playwright/test';
import {
  readParams,
  getAppUrl,
  getAuthEndpoint,
  getAuthTicket,
  hasAuthTicket,
} from '../pest-e2e/core.mjs';

test('example flow', async ({ page, request }) => {
  const params = await readParams();
  const baseUrl = getAppUrl(params);

  if (hasAuthTicket(params)) {
    const res = await request.post(getAuthEndpoint(params), {
      data: {
        ticket: getAuthTicket(params),
        mode: params.auth?.mode ?? 'session',
        guard: params.auth?.guard ?? 'web',
      },
      headers: { 'X-Pest-E2E': '1' },
    });
    expect(res.ok()).toBeTruthy();
  }

  await page.goto(`${baseUrl}/`);
});
```

Adjust import path to match your spec file’s depth under `->dir()`.

## Pest snippet (filter + run)

```php
e2e('frontend')
    ->only('UserProfile')
    ->actingAs($user)
    ->withParams(['name' => 'E2E User'])
    ->run();
```

## Installer and publishing

- **`php artisan pest-e2e:install`** — interactive setup; **`--yes`** / **`--unattended`** for CI.
- Useful flags: **`--publish-config`**, **`--publish-base-test-case`**, **`--publish-js-harness`**, **`--publish-js-playwright`**, **`--add-csrf-exclusion`**, **`--setup-env-testing`**, **`--configure-phpunit`**, **`--package-manager=`**.

For existing options, run **`php artisan pest-e2e:install --help`**.

## When editing this package vs an app

- **Application:** extend `E2ETestCase`, keep browser code in JS, tune `config/pest-e2e.php` and `.env.testing`.
- **Package (`valcuandrei/pest-e2e`):** preserve **v1 API and JSON report contract** documented in `.docs/`; avoid breaking `pest-e2e.v1` consumers.

## Documentation

Use **`search-docs`** for framework and Playwright. For pestE2E-specific behavior, prefer the package **README** and **`.docs/`** in the repository.
