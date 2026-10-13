# Run-directory pruning and runtime state — design notes

> **Status:** implemented. Companion to `run-directory-pruning-investigation.md`, which records why.
> **Date:** 2026-10-13

## Layout

```
<base>/<target>/<runId>/                               reports; this is Playwright's --output
<base>/.pest-e2e-state/<target>/<runId>/run.json       ownership record; flock-ed while the run is active
<base>/.pest-e2e-state/<target>/<runId>/runtime/       private per-run files (storageState.json), mode 0700
<base>/.pest-e2e-state/prune.json                      {lastSuccessAt, lastAttemptAt, backlog}
<base>/.pest-e2e-state/prune.lock                      sweep lock
```

`<base>` is `reports.base_dir`, resolved by `ReportPathPolicy` as before.

Ownership lives in the sidecar because Playwright's first task ("clear output") deletes everything in `--output`, including the `.pest-e2e-run` marker earlier versions relied on. The marker is still written and still honoured when found, so run directories from before this change remain prunable.

## Call order in `E2ERunner::run()`

1. `ReportDirectoryManager::begin()` — create the report directory, write the registry record, take an exclusive `flock` on it.
2. Admission control.
3. Warm-browser endpoint.
4. `PEST_E2E_RUNTIME_DIR` added to the child environment.
5. `ReportDirectoryManager::pruneIfDue()` — throttled sweep check.
6. `lateParams` — the auth ticket is issued here.
7. Build the plan; `NodeCompileCache`; launch `playwright test`.
8. Read the report.
9. `finally`: `ReportDirectoryManager::finish()` — delete the runtime directory, write the final state, release the lock, remove the legacy `<target dir>/.pest-e2e/<runId>/storageState.json` if an old published harness wrote one.

Pruning sits at step 5 rather than literally last because the ticket (step 6) must be the final thing before launch: nothing that can take time may run after it. Allocation (step 1) stays first so the run is registered and locked before it can wait in the admission queue.

## Run states

| State | Record | Lock | Treated as |
|---|---|---|---|
| Allocated / active | `state: running` | held | Never deleted; runtime files never touched |
| Completed | `passed` / `failed`, `finishedAt` | free | Subject to retention from its finish time |
| Interrupted (PHP exception) | `interrupted` | free | Same as completed; runtime already deleted by `finish()` |
| Abandoned (process died) | `running` | free | Runtime deleted by the next sweep once the record is over 60 s old; report subject to retention from allocation time |
| Legacy marked | `.pest-e2e-run` in the report directory, no record | none | Owned; retention applies; grace period always applies because there is no lock to consult |
| Unmarked | neither | none | Never deleted automatically; `--legacy` / `--delete-unmarked-legacy-runs` only |

The lock is the single source of truth for "active". It is released by the kernel when a process dies, so no PID checks or heartbeats are needed.

## Retention

Owned runs are sorted newest first by record time. A run is deleted when `age > keep_days` **or** `rank >= keep_runs`, unless it is the caller's own run, inside the grace period, or locked. A rule set to 0 is off. This is the same OR rule the previous implementation used; only the defaults changed (`keep_runs` 50 → 500) and the grace period was added.

The run's lock is taken (non-blocking) before deletion and held during it, which closes the race between "is it active?" and "delete it".

## Throttle

`pruneIfDue()` reads `prune.json`. A sweep is due when `now - max(lastSuccessAt, lastAttemptAt) >= interval_minutes`. If due, it tries `flock(LOCK_EX | LOCK_NB)` on `prune.lock`; failure means another worker is sweeping and this one returns. Under the lock it re-reads the stamp, records `lastAttemptAt`, sweeps, and on success records `lastSuccessAt`.

- A failed sweep does not update `lastSuccessAt`, but `lastAttemptAt` throttles the retry, so a persistent failure does not cause a scan on every test.
- Failures are reported once per PHP process.
- An automatic sweep deletes at most 200 runs. When it stops at that limit it records `backlog: true`, which shortens the next interval to one minute.
- With `reports.prune.enabled = false` the sweep still runs but only removes stale runtime files.

## Deletion boundaries

Every deletion goes through `SafeDirectoryRemover::removeTreeInside($directory, $root)`, which refuses anything that is a symbolic link or does not resolve strictly inside `$root`. On top of that:

- Report and legacy directories must be exactly `<base>/<target>/<run>`.
- Registry and runtime directories must be inside `<base>/.pest-e2e-state`.
- A base directory that is itself a symbolic link is refused; ancestors may be links (shared `storage/` deployments).
- Directory scans skip links; recursive removal unlinks links without following them.
- The legacy target cleanup removes one named file and then `rmdir`s its directory, which fails harmlessly if anything else is in it.

`tests/Unit/DeletionBoundaryTest.php` plants an outside tree, links to it from every level, runs the most aggressive options, and requires the outside tree to be unchanged.

## Not covered

- `<target dir>/.pest-e2e/` directories left by earlier versions. See `legacy-target-state-cleanup-plan.md`.
- Params files for payloads over 8 KB (`<tmp>/pest-e2e/<target>/<runId>.json`) are still not removed. Low volume; noted in the investigation.
