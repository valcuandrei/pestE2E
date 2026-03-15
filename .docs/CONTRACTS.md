# CONTRACTS — Interop Contracts

## Env injection

Injected into every Node process:
- `PEST_E2E_TARGET`
- `PEST_E2E_RUN_ID`
- `PEST_E2E_PARAMS` (JSON)
- `PEST_E2E_PARAMS_FILE` (absolute path)

## Suite execution contract

The PHP `PlaywrightWorker` drives Playwright directly via its CLI.
Test filtering (`--grep`), headed mode (`--headed`), and debug mode (`--debug`) are passed as CLI arguments.

### Playwright integration

The PHP `PlaywrightWorker` drives Playwright directly via its CLI and reads the JSON report output.
Report conversion from Playwright's native format to `pest-e2e.v1` is handled by `PlaywrightParser` on the PHP side.

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
