# CONTRACTS — Interop Contracts

## Env injection
Injected into every Node process:
- `PEST_E2E_TARGET`
- `PEST_E2E_RUN_ID`
- `PEST_E2E_PARAMS` (JSON)
- `PEST_E2E_PARAMS_FILE` (absolute path)

## Suite execution contract

- JS suite must emit a **JSON report**
- The report must conform to the `pest-e2e.v1` schema
- File path must match target config

### Playwright Integration

When using the provided Playwright runner wrapper (`resources/js/pest-e2e/playwright/run.mjs`):
- Playwright produces its native JSON report format
- The wrapper automatically converts it to the canonical `pest-e2e.v1` schema
- The canonical report is written to the configured report path
- Raw Playwright report is preserved at `.pest-e2e/{runId}/playwright-report.json`

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
- Server validates ticket and authenticates browser
- Ticket is single-use, short-lived, testing-only

## Auth action contract

The package provides a testing-only auth endpoint:

POST `/.well-known/pest-e2e/auth/login`

The endpoint validates E2E auth tickets and delegates
authentication to an application-defined action.

### Request
```json
{
  "ticket": "ticket-123",
  "mode": "session",
  "guard": "web"
}
```

### Responses
- `200` `{ "ok": true }` (session mode)
- `200` `{ "ok": true, "token": "..." }` (sanctum mode)
- `401` `{ "ok": false, "message": "..." }` (invalid/expired/used ticket)
- `501` `{ "ok": false, "message": "..." }` (Sanctum missing or unsupported)

### Security
- The route is only loaded in `testing`
- Requires header `X-Pest-E2E: 1` by default

### Contract

`E2EAuthActionContract`

Responsibilities:
- Receive a validated ticket payload
- Authenticate the browser session or issue a token
- Return an HTTP response

The package binds a default implementation.
Applications may rebind the contract to customize behavior.

### Stability

- The HTTP endpoint path and request shape are **v1 stable**
- The internal action binding is explicitly extensible
