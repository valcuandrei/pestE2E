---
name: pest-e2e-development
description: Develops and maintains pestE2E (valcuandrei/pest-e2e) JavaScript-driven E2E tests orchestrated from Pest. Activates when working with e2e() targets, E2ETestCase, Playwright specs, resources/js/pest-e2e harness, pest-e2e auth tickets, config/pest-e2e.php, pest-e2e:install, agent/PAO JSON output (PEST_E2E_AGENT_OUTPUT, --pest-e2e-agent-output), parallel browser runs, or when the user mentions browser E2E from Pest, pestE2E, or JS-owned E2E.
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

CLI overrides: **`--browse`** / **`--headed`**, **`--debug`**, **`--run-using=npm|yarn|pnpm|bun`**, **`--pest-e2e-agent-output`** / **`--pest-e2e-json`**.

## Playwright timeouts (hard rule)

- **Default: 5 seconds** — `resources/js/e2e/playwright.config.js` sets `timeout`, `expect.timeout`, `actionTimeout`, and `navigationTimeout` to `5000`. Do not change these values upward.
- **On timeout, fix the test or app** — never “fix” by increasing timeouts. Typical root causes:
  - Wrong or brittle selector (prefer `getByTestId` / `data-test` aligned with the Vue page)
  - Unexpected **redirect** (guest login, email verification, Fortify confirm-password)
  - Wrong `baseURL` / path (`page.goto` not landing on the screen under test)
  - Assertion before Inertia/Vue has rendered (use a stable locator or correct post-navigation expectation)
- **Forbidden:** `{ timeout: 15_000 }`, `test.setTimeout`, editing config to 30s, or suggesting “try a higher timeout” without fixing the underlying issue.

## Output modes

| Mode | Passed E2E terminal output | Failed E2E terminal output |
| ---- | -------------------------: | -------------------------: |
| normal | yes | yes |
| `--compact` | no | yes |
| `--parallel` | no | yes |
| agent / PAO (`PEST_E2E_AGENT_OUTPUT=1`, `--pest-e2e-agent-output`, `agent_output` config, or agent-detector) | compact JSON only | compact JSON with `php_test`, `failures`, `error` |

Agent mode suppresses Pest/Collision human output. With `--parallel`, workers write JSON summaries to disk and the coordinator prints one JSON line per E2E run at the end.

```bash
PEST_E2E_AGENT_OUTPUT=1 php artisan test ./tests/Browser --parallel
```

## Reports and pruning

Playwright artifacts: `{reports.base_dir}/{target}/{runId}` (default under `storage/framework/testing/pest-e2e`). Old marked run dirs are pruned per `reports.prune`; the current run is never deleted. JSON test results are parsed from stdout in memory (`pest-e2e.v1`), not stored as report files.

## Parallelism

`php artisan test --parallel` is supported when each worker has its own database (`{database}_test_{TEST_TOKEN}` via Laravel parallel testing traits). pestE2E assigns ports per worker, scopes auth tickets, and passes the matching `APP_URL` to Playwright.

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
