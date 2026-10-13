# Legacy target-state backlog — one-time cleanup plan

> **Status:** plan only. **Nothing has been deleted.** The destructive step needs Andy's approval.
> **Date:** 2026-10-13
> **Scope:** ShowMyWork, `resources/js/e2e/.pest-e2e/`. This tree is deliberately not handled by `pest-e2e:prune-reports`, which only works inside the report directory.

## Inventory (read-only, taken 2026-10-13 05:15 EEST)

| | |
|---|---|
| Path | `/home/andy/www/ShowMyWork/resources/js/e2e/.pest-e2e/` |
| Entries | 30,254 directories, 0 symbolic links, 0 plain files at the top level |
| Named as a run ID (16 hex characters) | 30,250 |
| …containing exactly `storageState.json` | 29,954 |
| …empty | 296 |
| Other names | 4: `1789609505628`, `1789609515161` (timestamp fallbacks, `storageState.json` only), `default` and `diag-run-1789596105` (each holds a `playwright-output/` tree with traces from manual runs) |
| Files | 29,965 |
| Size | 31.5 MiB of file content; 158 MB apparent, 237 MB allocated on disk |
| Oldest / newest | 2026-04-23 02:59 / 2026-10-13 04:16 |
| Tracked by Git | No (`/resources/js/e2e/.pest-e2e` is in ShowMyWork's `.gitignore`) |

About 207 of these were added on 2026-10-13 by the Step 32 benchmarks, before the fix. Since the fix, the count has not changed across 30 further runs.

## Why it matters

Playwright walks the whole test directory on every invocation. Measured "load tests" time for `--list` of one spec:

| Test directory | Time |
|---|---|
| ShowMyWork `resources/js/e2e` with this backlog | 5.2 s |
| Scratch target using the new harness, after 12 real runs | 0.06 – 0.19 s |
| Copy of the specs without `.pest-e2e` | 0.08 – 0.12 s |

Expected improvement after cleanup: about 5 seconds off every Playwright invocation in ShowMyWork.

## Candidates

A directory is a candidate only when all of these hold:

1. It is a direct child of the path above and a real directory (not a link).
2. Its name is 16 lowercase hex characters.
3. It is empty, or its only entry is a regular file named `storageState.json`.
4. It was last modified more than 60 minutes ago (active-run exclusion; also run this only while no tests are running).

That selects 30,250 directories today (minus any modified in the last hour). The four differently named directories are **not** candidates. Two of them hold traces from manual runs; decide about those by hand.

## Procedure

1. Make sure no test run is in progress.
2. Dry run: print the inventory and the first few candidates and exclusions.
3. Review the counts against the table above.
4. Run again with `--delete`.
5. Check: `ls resources/js/e2e/.pest-e2e | wc -l` should show only the excluded entries, and a Playwright `--list` should load in well under a second.

The script below was verified on a small replica (candidates removed; a recent directory, one with extra files, one with a `playwright-output/` tree, a non-run name and a symbolic link all kept, and the link's target untouched). A **dry run** against the real directory on 2026-10-13 reported 30,250 candidates and the 4 exclusions listed above, and changed nothing. A copy is at `ShowMyWork/storage/framework/testing/step32-prune-research/cleanup-legacy-target-state.php`.

Script (the `--delete` step has not been run; run from the ShowMyWork root):

```php
<?php
// usage: php cleanup-legacy-target-state.php [--delete]
$expected = getcwd().'/resources/js/e2e/.pest-e2e';
$root = realpath($expected);

if ($root === false || $root !== $expected || is_link($expected)) {
    fwrite(STDERR, "Refusing: run from the ShowMyWork root; expected a real directory at {$expected}\n");
    exit(1);
}

$delete = in_array('--delete', $argv, true);
$cutoff = time() - 3600;
$candidates = $excluded = [];

foreach (scandir($root) as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }

    $path = $root.'/'.$name;
    $reason = null;

    if (is_link($path) || ! is_dir($path)) {
        $reason = 'not a real directory';
    } elseif (preg_match('/^[0-9a-f]{16}$/', $name) !== 1) {
        $reason = 'name is not a run id';
    } elseif (filemtime($path) > $cutoff) {
        $reason = 'modified within the last hour';
    } else {
        $entries = array_values(array_diff(scandir($path), ['.', '..']));
        $file = $path.'/storageState.json';

        if ($entries !== [] && ($entries !== ['storageState.json'] || is_link($file) || ! is_file($file))) {
            $reason = 'unexpected contents: '.implode(', ', array_slice($entries, 0, 3));
        }
    }

    $reason === null ? $candidates[] = $path : $excluded[$name] = $reason;
}

printf("%s\ncandidates: %d\nexcluded: %d\n", $root, count($candidates), count($excluded));

foreach ($excluded as $name => $reason) {
    echo "  keep {$name} — {$reason}\n";
}

if (! $delete) {
    echo "Dry run. Re-run with --delete to remove the candidates.\n";
    exit(0);
}

$removed = 0;

foreach ($candidates as $path) {
    $file = $path.'/storageState.json';

    if (is_file($file) && ! is_link($file)) {
        unlink($file);
    }

    // rmdir only succeeds on an empty directory, so anything unexpected stays.
    if (@rmdir($path)) {
        $removed++;
    }
}

echo "removed: {$removed}\n";
```

The script never recurses, never follows a link, deletes one known file name per directory, and uses `rmdir`, which cannot remove a directory that still has content.

## After cleanup

Republish the harness in ShowMyWork so storage state is written to the runtime directory in the first place:

```bash
php artisan vendor:publish --tag=pest-e2e-js-playwright --force
```

Until then pest-e2e removes each run's legacy file after the run, so the directory will not grow again either way.
