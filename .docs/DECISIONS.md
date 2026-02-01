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
- Sail is supported as a **reference environment**
- The plugin never detects Docker, Sail, or host environments
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
1. Pest creates a user via factories
2. Pest issues a short-lived login ticket for that user
3. Ticket is passed to JS via params
4. JS calls a testing-only login endpoint
5. App sets normal auth cookies

---

## 7) Env and params contract
When spawning Node, the plugin injects:
- `PEST_E2E_PROJECT`
- `PEST_E2E_RUN_ID`
- `PEST_E2E_PARAMS` (JSON) OR `PEST_E2E_PARAMS_FILE`

---

## 8) Reporting format (v1)
- JUnit is required for v1
- Suite execution must emit a JUnit file
