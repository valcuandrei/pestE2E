## Improvements and optimizations plan

### Milestone 1 — Parallel-safe execution

**Goal:** make this work reliably:

```bash
php artisan test --parallel
```

Implement:

1. detect Pest/Laravel parallel worker token
2. derive one server port per worker
3. pass worker-specific env into the server runner
4. ensure Playwright receives the matching `APP_URL`
5. document DB-per-worker requirements

Acceptance:

```text
--parallel --processes=4
```

runs browser tests without port collisions, DB collisions, or auth-ticket bleed.

---

### Milestone 2 — Report directory + pruning

**Goal:** stop Playwright reports from filling `resources/js/e2e`.

Restore public API:

```php
e2e('frontend')
    ->reportDir(storage_path('framework/testing/pest-e2e'))
    ->run();
```

Add config:

```php
'reports' => [
    'dir' => storage_path('framework/testing/pest-e2e'),
    'prune' => [
        'enabled' => true,
        'keep_runs' => 50,
        'keep_days' => 7,
    ],
],
```

Acceptance:

```text
reports go to configured dir
old run dirs are safely pruned
current run is never deleted
```

---

### Milestone 3 — CLI output noise control

**Goal:** successful E2E output should not spam compact/parallel runs.

Rules:

| Mode         | Passed E2E output | Failed E2E output |
| ------------ | ----------------: | ----------------: |
| normal       |               yes |               yes |
| `--compact`  |                no |               yes |
| `--parallel` |                no |               yes |

Acceptance:

```bash
php artisan test --compact
php artisan test --parallel
```

only prints E2E details when something fails.

---

### Milestone 4 — PAO / agent-friendly output

**Goal:** compact structured output for agents.

PAO detects AI-agent environments and swaps verbose Pest/PHPUnit/Paratest output for compact JSON without changing normal terminal output. It supports Pest, PHPUnit, Paratest, PHPStan, Rector, and Artisan output. ([GitHub][1])

Pest E2E should follow that pattern:

```text
human terminal -> pretty output
agent/PAO mode -> compact JSON summary
```

Acceptance:

```json
{
  "target": "frontend",
  "result": "failed",
  "passed": 3,
  "failed": 1,
  "duration_ms": 1163,
  "report_dir": "..."
}
```

## Suggested implementation order

```text
1. Parallel worker isolation
2. Report dir + pruning
3. Output suppression
4. PAO-compatible JSON
```

That order is clean because #3 and #4 depend on knowing exactly where/when output is produced.

[1]: https://github.com/laravel/pao?utm_source=chatgpt.com "laravel/pao: PAO is agent-optimized output for PHP testing ..."
