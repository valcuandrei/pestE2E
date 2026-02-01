# DECISIONS — Set in Stone

This document defines non-negotiable architectural decisions.
Any change here is a breaking change.

---

## 0) Core philosophy

- All browser tests are written in **JavaScript**
- PHP never exposes browser actions
- Pest is an **orchestrator**, not a test authoring API
- JS runner logic is replaceable
- Lock-in is explicitly avoided

---

## 1) No Selenium

- Selenium is **explicitly unsupported**
- No WebDriver
- No Selenium containers
- No Grid
- No Dusk-style infrastructure

Rationale:
Selenium adds flakiness, infrastructure complexity, and conflicts with
modern JS-first E2E tooling.

---

## 2) Default JS runner: Playwright (without lock-in)

- Playwright is the default and reference runner
- Public API must not expose Playwright concepts
- Other JS runners may be used if they can:
  - be executed as a process
  - emit machine-readable results (JUnit v1)

---

## 3) Execution environment

### Decision

- The plugin **does not require Sail**
- Sail is supported as a **first-class reference environment**
- Node may execute:
  - inside Docker (e.g. Sail)
  - on the host system

The plugin must not assume Docker or Sail is present.

### Reference setup (Sail)

- When using Sail:
  - Node + Playwright run inside `laravel.test`
  - App, DB, and tests share the same Docker network

### Non-Sail setups

- Node may run on the host
- App may run via:
  - Sail
  - local PHP server
  - Valet / Herd / custom Docker

The only requirement is that JS tests can reach the app via HTTP.

---

## 4) Headed debugging

- Headed mode is **optional**
- Headed debugging is supported via:
  - WSLg passthrough (when running in Docker)
  - Native browser window (when running on host)

Headed mode is a **debugging feature**, not a runtime requirement.

Default debugging strategy:
- headless execution
- Playwright traces, videos, screenshots

---

## 5) Database consistency

- JS tests never connect to the database directly
- DB state is controlled exclusively from Pest:
  - factories
  - migrations
  - refresh/reset traits

JS tests interact with the app **only over HTTP**.

This guarantees:
- same database
- same env
- no duplicated DB config in Node

---

## 6) Authentication handoff

### Problem

- Laravel session cookies are encrypted and signed
- `actingAs($user)` does not produce a browser-authenticated session
- Copying cookies between PHP and JS is fragile

### Decision

Authentication is transferred using a **one-time E2E login ticket**.

Flow:
1. Pest creates a user via factories
2. Pest issues a short-lived login ticket for that user
3. Ticket is passed to JS via params
4. JS calls a testing-only login endpoint
5. App sets normal auth cookies

No cookie copying. No Sanctum internals.

---

## 7) Env and params contract

When spawning Node, the plugin always injects:

- `PEST_E2E_PROJECT`
- `PEST_E2E_RUN_ID`
- `PEST_E2E_PARAMS` (JSON) OR `PEST_E2E_PARAMS_FILE`

This contract is runner-agnostic.

---

## 8) Reporting format (v1)

- JUnit is the required format for v1
- Suite execution must emit a JUnit file
- Other formats may be supported later

JUnit is chosen because it is:
- widely supported
- framework-agnostic
- easy to ingest

---
