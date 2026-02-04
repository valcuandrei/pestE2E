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
- JS receives `params.auth.ticket`
- JS calls a testing-only login endpoint
- Server validates ticket and authenticates browser
- Ticket is single-use, short-lived, testing-only

## Auth action contract

The package provides a testing-only auth endpoint:

POST `/.well-known/pest-e2e/auth/login`

The endpoint validates E2E auth tickets and delegates
authentication to an application-defined action.

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
