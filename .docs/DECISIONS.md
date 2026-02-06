# DECISIONS — Set in Stone

This document defines non-negotiable architectural decisions.
Any change here is a breaking change.

---

## 0) Core philosophy

- Laravel is the **authoritative backend**
- Pest owns test orchestration and state
- All browser tests are written in **JavaScript**
- PHP never exposes browser actions
- JS runners are **replaceable adapters**
- The system is a **contract bridge**, not a browser DSL

---

## 1) No Selenium
- Selenium is **explicitly unsupported**
- No WebDriver
- No Selenium containers
- No Grid
- No Dusk-style infrastructure

---

## 2) Default JS runner: Playwright (without lock-in)
- Playwright is the default and reference runner
- Public API must not expose Playwright concepts
- Other JS runners may be used if they can:
  - be executed as a process
  - emit machine-readable results (JSON `pest-e2e.v1`, adapters allowed)

---

## 3) Execution environment

### Decision
- The package **does not require Sail**
- Sail is supported as a **reference environment**
- The package never detects Docker, Sail, or host environments
- Commands are executed **exactly as provided**, in the same environment Pest is running in

The execution strategy is defined entirely by the configured command.

---

## 4) Headed debugging
- Headed mode is optional
- Supported via:
  - WSLg passthrough (when running in Docker)
  - native browser window (when running on host)

Default debug strategy:
- headless execution
- Playwright traces, videos, screenshots

---

## 5) Database consistency
- JS tests never connect to the database directly
- DB state is controlled exclusively from Pest
- JS tests interact with the app **only over HTTP**

---

## 6) Authentication handoff

Authentication is transferred using a **one-time E2E login ticket**.

Flow:
1. Pest creates a Laravel user (factories, seeders, personas)
2. Pest issues a short-lived, single-use auth ticket
3. Ticket is passed to JS via `params.auth.ticket`
4. JS calls a **testing-only auth endpoint** provided by the package
5. The app authenticates the browser using:
   - session cookies (default)
   - or Sanctum tokens (optional)

Tickets are single-use, short-lived (default 60s), and validated
by a small package-owned ticket store. The auth endpoint is only
loaded in `testing` and gated by a header (`X-Pest-E2E: 1` by default).

### Server-side responsibility

The package provides the auth route, but delegates
authentication behavior to a rebindable action:

- `E2EAuthActionContract`

Apps may rebind this action to customize:
- guards
- multi-tenancy
- Sanctum abilities
- token vs cookie behavior

---

## 7) Env and params contract
When spawning Node, the package injects:
- `PEST_E2E_TARGET`
- `PEST_E2E_RUN_ID`
- `PEST_E2E_PARAMS` (JSON) OR `PEST_E2E_PARAMS_FILE`

---

## 8) Reporting format (v1)

- The v1 reporting format is **JSON**
- JS runners must emit a JSON report matching the `pest-e2e.v1` schema
- The report must include:
  - target
  - runId
  - stats
  - test list with pass/fail + optional error info
- JUnit support may be added later as an adapter
