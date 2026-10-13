# Run-directory pruning — investigation

> **Status:** investigation complete. Nothing implemented, nothing deleted. Awaiting Andy's approval.
> **Owner:** Andy
> **Worker:** Claude (Opus 5.5)
> **Date:** 2026-10-13
> **Package HEAD:** `b7c4550` on `fix/parallel-auth-ticket-lifetime`
> **Measured on:** ShowMyWork, Playwright 1.63.0, Node 24.16.0 (Sail container)

Statements are labelled **[fact]** (read in source or history) or **[observed]** (measured here).

---

## 1. Summary

- The 30,047 directories are Playwright **storage-state files**, one per run, written by the package's JavaScript harness into the Playwright test directory. **No version of pest-e2e has ever cleaned them up.** The historical pruner and today's pruner both cover a different tree (the report directory under `storage/`).
- Today's report pruner is **not working in real runs**, independently of this. It only deletes directories that contain a marker file, and Playwright's own "clear output" step deletes that marker at the start of every run. In ShowMyWork 43 of 11,742 report directories still have the marker. ShowMyWork has also disabled pruning in its published config.
- The 5-second discovery cost can be removed without any pruning by not writing runtime state inside the test directory. `testIgnore` does not help. `respectGitIgnore` helps only with a `.gitignore` inside the test directory and changes native discovery rules, so it is not something the package should impose.
- The historical implementation is small and partly worth restoring: its **`pest-e2e:prune-reports` command with `--dry-run` and `--all`** is the piece that is missing today. Its deletion logic is not safe enough to restore as it was.

---

## 2. What pruning existed historically

| Commit | Date | What |
|---|---|---|
| `3e9b326` | 2026-02-12 | Storage state first written to `.pest-e2e/<runId>/storageState.json` in the target directory. No cleanup. |
| `8b12dd5` | 2026-02-21 | Reports moved to `storage/framework/testing/pest-e2e/<runId>/`. Storage state stayed in the target directory. |
| `87d4d37` | 2026-02-21 | **First pruner.** `Actions\ReportsPrunerAction`, command `pest-e2e:prune-reports {--unit=days\|items} {--value=30} {--all} {--dry-run}`, config `reports.dir` and `reports.prune.{enabled,unit,value}` (default: enabled, 30 days). Called on every `E2ERunner::run()`. |
| `701e9ff` | 2026-03-15 | **Removed** in "simplify E2E flow and remove unused features". The message lists "Drop reports pruning" among other removals and gives no further reason. |
| `d4e6b83` | 2026-05-18 | **Restored in a new form**: `Support\ReportDirectoryManager`, marker file `.pest-e2e-run`, config `reports.prune.{enabled,keep_runs,keep_days}` (50 runs, 7 days), current-run protection. No command, no dry run. |
| `6b26a8e` | 2026-09-17 | `ReportPathPolicy`: per-user, per-project fallback location. Pruning logic unchanged. |

The first pruner (`87d4d37`) **[fact]**:

- Listed the direct children of the reports directory and deleted by modification time, either older than N days or beyond the newest N.
- Had no marker or ownership check: every subdirectory of the configured path was eligible, including with `--all`.
- Had no protection for the current run or for other workers' runs.
- Used `File::deleteDirectory`.
- Never looked at the target directory's `.pest-e2e/`.

So it cannot be restored as it was, and it would not have prevented the 30,047 directories. Its command interface is the useful part.

---

## 3. What exists today

`ReportDirectoryManager::prepare()` runs before every E2E run **[fact]**:

1. Creates `<base>/<target>/<runId>/` and writes the marker `.pest-e2e-run` into it.
2. Calls `prune()`, which walks the **entire** base directory recursively, collects directories containing the marker, sorts by marker mtime, and deletes those beyond `keep_runs` or older than `keep_days`, except the current run.

Problems found:

| # | Problem | Evidence |
|---|---|---|
| P1 | **The marker does not survive a real run.** pest-e2e passes the run directory as `--output`. Playwright's first task, "clear output" (`createRemoveOutputDirsTask`, `lib/runner/index.js:6398`), empties that directory before anything else, marker included. Marked directories are therefore only runs where Playwright never got that far. | **[observed]** 43 of 11,742 run directories have the marker. The three newest run directories contain only `.last-run.json` and test output. |
| P2 | ShowMyWork's published config has `reports.prune.enabled` defaulting to `false`; the shipped default is `true`. | **[fact]** `config/pest-e2e.php` in ShowMyWork |
| P3 | Full recursive scan on every test. | **[observed]** 462 ms for the current tree (12,579 directories), read-only replica of the same walk |
| P4 | `keep_runs = 50` is smaller than one suite. ShowMyWork's Browser suite has 76 test files and makes at least that many runs, so a failure early in a suite would lose its trace before the suite ends, if pruning worked. | **[fact]** by arithmetic |
| P5 | Only the current worker's current run is protected. Other workers' active runs are safe only because they are recent. With a small `keep_runs` that is a race. | **[fact]** |
| P6 | Parallel workers prune concurrently with no coordination. Deletes are `@`-suppressed so it does not crash, but the work is duplicated. | **[fact]** |
| P7 | Nothing handles the target directory's `.pest-e2e/`. | **[fact]** |
| P8 | Params files for large payloads (`<tmp>/pest-e2e/<target>/<runId>.json`) are never removed. Low volume. | **[fact]** `TempParamsFileWriter` |

Existing behaviour that is right and should be kept: the marker as proof of ownership, never deleting unmarked directories automatically, current-run protection, not following symlinks (the iterator does not follow them and `unlink` removes the link itself).

There are two separate trees with two separate needs, and today only one has any policy:

| Tree | Contents | Needed after the run? | Policy today |
|---|---|---|---|
| Report directory `<base>/<target>/<runId>/` | Traces, screenshots, videos, `.last-run.json` | Yes, for diagnosing failures | Marker-based pruning, broken by P1 |
| Target `.pest-e2e/<runId>/` | `storageState.json` | No | None |

---

## 4. The 30,047 directories

**[observed]** in `ShowMyWork/resources/js/e2e/.pest-e2e/` (read-only inspection):

- **Created by** `globalSetup` in `resources/js/pest-e2e/playwright.mjs`: `storageStatePath()` returns `.pest-e2e/<PEST_E2E_RUN_ID>/storageState.json`, relative to the working directory, which is the target directory and, with the stub config, the Playwright `testDir`.
- **Names:** 30,133 entries of 16 hex characters (the run ID), plus `default/`, one `diag-run-…` and two timestamp names (the fallbacks when no run ID is set).
- **Contents:** in a sample of 300, 297 hold exactly one file, `storageState.json`; 3 are empty. No specs, reports or traces. The file holds the session cookies for an authenticated run, or an empty state.
- **Age:** April 490, May 16,176, June 1,302, September 6,391, October 5,778. Oldest 2026-04-23.
- **Size:** 235 MB.
- **Read by** the Playwright worker of the same run, when it creates a browser context. **Not referenced again** after the Playwright process exits: nothing in PHP or JS reads it back.
- **Concurrency:** one directory per run ID, so parallel workers never share one.
- **Crash recovery:** nothing depends on them.
- **Why they remain:** there is no `globalTeardown`, and the PHP side does not know the path.
- **Which are stale:** every directory whose run has finished. Only a run that is executing right now has a live one. Age is a safe test: anything older than the longest possible run is dead.
- ShowMyWork's `.gitignore` already ignores the directory, so none of it is tracked.
- ShowMyWork's copy of the harness is a **published** file and already differs from the package's, so a harness fix reaches consumers only when they republish.

Should Playwright traverse them? No. They are not tests and Playwright has no reason to see them.

---

## 5. Discovery overhead

**[fact]** `collectFiles` (`lib/runner/index.js:2262`) walks the whole `testDir`, skipping only directories named `node_modules`. `testIgnore` is applied to the resulting file list, after the walk. `.gitignore` files are honoured only when `respectGitIgnore` is true, which defaults to false whenever `testDir` is set (`lib/common/index.js:610`), and only `.gitignore` files inside `testDir` are read; a rule in the repository root is not.

**[observed]** Time of Playwright's "load tests" step for `--list userProfile.spec.ts`, three runs each:

| Test directory | Config | Time |
|---|---|---|
| ShowMyWork's real `resources/js/e2e` | stock | 5.1 – 5.3 s |
| Copy of the specs without `.pest-e2e` | stock | 0.08 – 0.12 s |
| Scratch replica with 30,047 generated run directories | stock | 5.6 – 6.4 s |
| Same replica | `testIgnore: ['**/.pest-e2e/**']` | 5.4 – 5.7 s |
| Same replica | `respectGitIgnore: true`, no `.gitignore` in `testDir` | 5.5 – 5.7 s |
| Same replica | `respectGitIgnore: true` and `testDir/.gitignore` containing `.pest-e2e` | **0.09 – 0.10 s** |
| Same replica | `.gitignore` present, stock config | 5.5 – 6.1 s |

Conclusions:

- **Yes, discovery exclusion alone removes the penalty**, regardless of how many directories exist.
- `testIgnore` is not an exclusion for this purpose.
- `respectGitIgnore` works but is a consumer config change that also makes Playwright skip any git-ignored spec, so it changes native discovery. It is a valid workaround a consumer can choose; the package should not set it for them.
- The clean fix is to stop writing runtime state inside the test directory. The per-run report directory is already passed to the JS process as `PEST_E2E_REPORT_DIR`, it is outside `testDir`, and Playwright's "clear output" step runs before `globalSetup`, so a storage-state file written there by `globalSetup` survives the run.

---

## 6. Proposal (for approval, not implemented)

### A. Stop creating state in the test directory

1. `storageStatePath()` returns `<PEST_E2E_REPORT_DIR>/storageState.json` when that variable is set, and the current path otherwise (running Playwright by hand without pest-e2e).
2. The stub config's `outputDir` default moves the same way. It is already overridden by `--output` in pest-e2e runs.
3. After the Playwright process exits, PHP deletes that run's `storageState.json`, since it holds session cookies and is not a diagnostic artifact. Kept when `--debug` is used.
4. Because consumers hold published copies of the harness, PHP also removes `<target dir>/.pest-e2e/<runId>/` for the run it just finished. That is one known path per run, belongs to this worker only, and needs no scan. It makes old published harnesses stop accumulating without a republish.

### B. Make report pruning actually work

5. Write the marker **after** the Playwright process exits as well as before, so it survives "clear output" (fixes P1).
6. Scan one level only (`<base>/<target>/*`) instead of the whole tree, and prune **once per PHP process** rather than before every test (fixes P3, bounds the work).
7. Take a non-blocking lock while pruning; a worker that cannot get it skips pruning (fixes P6).
8. Add a grace period: never prune a run younger than N minutes, whatever the count says (fixes P5, and protects the current suite's failures).

### C. Restore the historical command

9. `pest-e2e:prune` with `--dry-run` and `--all`, from `87d4d37`, adapted to the marker and to both trees. This is how existing backlogs get cleared deliberately, with a dry run first.
10. Unmarked legacy directories (the 11,699 in ShowMyWork's report tree, and the 30,047 storage-state directories) are **never** touched by automatic pruning. The command handles them only with an explicit flag, and only entries that are direct children of a pest-e2e-owned directory, match the run-ID shape, are not symlinks, and contain nothing but known pest-e2e files.

### Configuration

Existing keys keep their names and meaning:

```php
'reports' => [
    'base_dir' => env('PEST_E2E_REPORTS_BASE_DIR'),
    'prune' => [
        'enabled'       => env('PEST_E2E_REPORT_PRUNE_ENABLED', true),
        'keep_runs'     => (int) env('PEST_E2E_REPORT_PRUNE_KEEP_RUNS', 500),   // was 50
        'keep_days'     => (int) env('PEST_E2E_REPORT_PRUNE_KEEP_DAYS', 7),
        'grace_minutes' => (int) env('PEST_E2E_REPORT_PRUNE_GRACE_MINUTES', 60), // new
    ],
],
```

A run is pruned when it is older than `grace_minutes` **and** (older than `keep_days` **or** beyond the newest `keep_runs`). Storage state has no retention setting: it is removed when its run ends.

### Safety properties and how each is met

| Requirement | Mechanism |
|---|---|
| Active runs protected | Own run excluded; all runs inside the grace period excluded |
| Parallel isolation, no deletion races | Each worker deletes only its own run's state; pruning under a non-blocking lock |
| Recent failure artifacts kept | Grace period, larger `keep_runs`, `keep_days` |
| Crashed or interrupted runs | Their report directories age out like any other; their state directory is removed by the command, or by a bounded sweep if approved (below) |
| No symlink escape | Never follow links; refuse a base or run directory that is itself a link |
| Never arbitrary user paths | Marker required for automatic deletion; fixed directory names; direct children only |
| No per-test full scan, bounded work | One-level scan, once per process; state removal is a single known path |
| No daemon, no dependency | In-process, standard library only |

---

## 7. Decisions for Andy

1. **Approve A (move storage state out of the test directory, remove it after the run)?** This alone ends both the accumulation and the discovery penalty for new runs.
2. **Approve B (repair report pruning)?** Including raising the default `keep_runs` from 50 to 500 and adding `grace_minutes`.
3. **Approve C (restore the prune command with dry run)?** And should it be allowed to clear unmarked legacy directories behind an explicit flag?
4. **Crash leftovers in old target directories:** rely on the command only, or also add a bounded automatic sweep (for example at most 200 entries per PHP process, older than 24 hours, run-ID-shaped names containing only `storageState.json`)? The sweep clears a 30,000 backlog gradually without anyone running a command, at the cost of one more automatic deletion path.
5. **ShowMyWork's existing backlog** (30,047 state directories, 11,742 report directories, 810 MB + 235 MB): delete once by hand now, or wait for the command? Deleting `resources/js/e2e/.pest-e2e/` is safe whenever no test is running, and immediately removes about 5 s from every Playwright invocation. I have not deleted anything.
6. **ShowMyWork's published config** disables pruning (`false` default). Change it there once pruning works?

---

## Appendix — reproducing

- History: `git log --all --oneline -S'prune' -- src config tests README.md`, then `git show 87d4d37`, `701e9ff`, `d4e6b83`.
- Marker census: `find storage/framework/testing/pest-e2e/frontend -mindepth 2 -maxdepth 2 -name .pest-e2e-run | wc -l` against `ls … | wc -l`.
- Discovery timings: `DEBUG=pw:test:task playwright test --list --reporter line userProfile.spec.ts` and read the "load tests" started/finished timestamps.
- Scratch replica with 30,047 generated directories and the two alternative configs: `ShowMyWork/storage/framework/testing/step32-prune-research/e2e-30k/` (git-ignored, safe to delete).
