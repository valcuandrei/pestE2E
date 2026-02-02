# CONTRACTS — Interop Contracts

## Env injection
Injected into every Node process:
- `PEST_E2E_PROJECT`
- `PEST_E2E_RUN_ID`
- `PEST_E2E_PARAMS` (JSON)
- `PEST_E2E_PARAMS_FILE` (absolute path)

## Suite execution contract
- JS suite must emit JUnit XML
- File path must match project config
- Nested suites supported

## call() contract
- Node harness loads module + export
- Context passed:
  - params
  - env
  - runId
  - project
- resolve → exit code 0
- throw/reject → non-zero exit
- stdout/stderr captured and surfaced

## Auth bridge contract
- JS receives `params.auth.ticket`
- JS calls a testing-only login endpoint
- Server validates ticket and authenticates browser
- Ticket is single-use, short-lived, testing-only
