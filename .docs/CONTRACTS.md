# CONTRACTS — Interop Contracts

## Env injection

Injected into every Node process:
- `PEST_E2E_TARGET`
- `PEST_E2E_RUN_ID`
- `PEST_E2E_PARAMS` (JSON)
- `PEST_E2E_PARAMS_FILE` (absolute path)
- `PEST_E2E_TEST_FILTER`
- `PEST_E2E_BROWSE`
- `PEST_E2E_DEBUG`

## Suite execution contract

- JS suite must emit a **JSON report**
- The report must conform to the `pest-e2e.v1` schema
- File path must match target config

### Playwright integration

When using the provided Playwright runner wrapper (`resources/js/pest-e2e/playwright/run.mjs`):
- Playwright produces its native JSON report format
- the wrapper converts it to canonical `pest-e2e.v1`
- canonical report is written to the configured report path
- raw Playwright report is preserved at:
  - `{reportsDir}/{runId}/playwright-report.json`

`{reportsDir}` defaults to `storage/framework/testing/pest-e2e`.

## call() contract
- Node harness loads module + export
- Context passed:
  - params
  - env
  - runId
  - target
- resolve → exit code 0
- throw/reject → non-zero exit
- stdout/stderr captured and surfaced

## Auth bridge contract
- JS receives `params.auth` payload
  - `ticket` (required)
  - `mode` (`session` or `sanctum`, default `session`)
  - `guard` (optional)
  - `meta` (optional)
- JS calls a testing-only login endpoint
- server validates ticket and authenticates browser
- ticket is single-use, short-lived, testing-only

## Auth action contract

The package provides a testing-only auth endpoint:

POST `/pest-e2e/auth/login` (default)

The endpoint validates E2E auth tickets and delegates
authentication to an application-defined action.

### Route configuration
- configurable: `config('pest-e2e.auth.route')`
- enabled by: `PEST_E2E_AUTH_ROUTE_ENABLED=true`
- gated by header (default): `X-Pest-E2E: 1` (configurable)

### Request
```json
{
  "ticket": "ticket-123",
  "mode": "session",
  "guard": "web"
}
```

### Responses
- `200` `{ }` (session mode)
- `200` `{ "token": "..." }` (sanctum mode)
- `401` `{ "message": "..." }` (invalid/expired/used ticket)
- `501` `{ "message": "..." }` (Sanctum missing or unsupported)

### Security
- the route is only loaded in `testing`
- disabled by default
- requires a header by default

### Contract

`\ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract`

Responsibilities:
- receive a validated ticket payload
- authenticate the browser session or issue a token
- return an HTTP response

Applications may rebind the contract to customize behavior.

### Stability
- the HTTP endpoint request/response shape is **v1 stable**
- internal action binding is explicitly extensible
