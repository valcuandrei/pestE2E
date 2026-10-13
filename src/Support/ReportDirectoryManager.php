<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Closure;
use RuntimeException;
use Throwable;
use ValcuAndrei\PestE2E\DTO\ReportPruneOptionsDTO;

/**
 * Allocates the per-run report directory and drives its lifecycle:
 *
 * 1. {@see begin()} creates the directory, registers the run in the sidecar
 *    registry and takes its active-run lock.
 * 2. {@see pruneIfDue()} is the cheap check made before every Playwright
 *    launch; at most once per interval, one worker sweeps old runs.
 * 3. {@see finish()} deletes the run's private runtime files, records how it
 *    ended and releases the lock.
 *
 * @internal
 */
final class ReportDirectoryManager
{
    private const RUN_MARKER = '.pest-e2e-run';

    private const FALLBACK_NAMESPACE = 'pest-e2e';

    private const SWEEP_STAMP_FILE = 'prune.json';

    private const SWEEP_LOCK_FILE = 'prune.lock';

    /**
     * Run directories one automatic sweep may delete, so a large backlog is
     * worked off over several sweeps instead of delaying one test.
     */
    private const MAX_DELETIONS_PER_SWEEP = 200;

    /** Seconds until the next sweep when the last one stopped at its limit. */
    private const BACKLOG_INTERVAL_SECONDS = 60;

    /** Directory the JS harness of earlier versions wrote per-run state into, inside the target directory. */
    private const LEGACY_TARGET_STATE_DIRECTORY = '.pest-e2e';

    private static bool $sweepFailureReported = false;

    /** @var (callable():string)|null */
    private $defaultBaseDirResolver;

    private readonly ReportRunRegistry $registry;

    private readonly ReportPruner $pruner;

    /**
     * @param  (callable():string)|null  $defaultBaseDirResolver
     *                                                            Test-only seam so unit tests can inject a controlled default path.
     *                                                            Production callers should always pass `null`, which uses the shipped
     *                                                            `storage_path('framework/testing/pest-e2e')` resolver.
     * @param  (Closure(string): void)|null  $diagnostic  Receives the one-line notice when a sweep cannot run; defaults to STDERR.
     */
    public function __construct(?callable $defaultBaseDirResolver = null, ?ReportRunRegistry $registry = null, ?ReportPruner $pruner = null, private readonly ?Closure $diagnostic = null)
    {
        $this->defaultBaseDirResolver = $defaultBaseDirResolver;
        $this->registry = $registry ?? new ReportRunRegistry;
        $this->pruner = $pruner ?? new ReportPruner($this->registry);
    }

    public function resolveRunDirectory(string $target, string $runId): string
    {
        return $this->runDirectory($this->baseDir(), $target, $runId);
    }

    /**
     * Allocate a run: create its report directory, register it and mark it active.
     */
    public function begin(string $target, string $runId): ReportRun
    {
        [$baseDir, $runDir] = $this->prepareRunDirectory($target, $runId);
        [$baseDir, $runDir] = $this->writeRunMarkerWithFallback($baseDir, $runDir, $target, $runId);

        return $this->registry->open($baseDir, $target, $runId, $this->safeSegment($target), $this->safeSegment($runId), $runDir);
    }

    /**
     * The base directory reports are written to, as pruning sees it.
     */
    public function baseDirectory(): string
    {
        return $this->baseDir();
    }

    /**
     * Sweep old runs if a sweep is due and no other worker is doing one.
     *
     * Called before every Playwright launch. The usual cost is reading one
     * small file. It never waits for another process and never throws: a
     * sweep that cannot run or fails is reported once and retried after the
     * normal interval.
     */
    public function pruneIfDue(ReportRun $current): void
    {
        if (! $current->isRegistered()) {
            return;
        }

        $stateRoot = ReportRunRegistry::stateRoot($current->baseDirectory);
        $stampFile = $stateRoot.'/'.self::SWEEP_STAMP_FILE;

        try {
            if (! $this->sweepIsDue($stampFile, time())) {
                return;
            }

            $lock = @fopen($stateRoot.'/'.self::SWEEP_LOCK_FILE, 'c');

            if ($lock === false) {
                return;
            }

            if (! @flock($lock, LOCK_EX | LOCK_NB)) {
                @fclose($lock);

                return;
            }

            try {
                $now = time();

                // Another worker may have finished a sweep while this one was
                // deciding; the stamp is only authoritative under the lock.
                if (! $this->sweepIsDue($stampFile, $now)) {
                    return;
                }

                $stamp = $this->readStamp($stampFile);
                $stamp['lastAttemptAt'] = $now;
                $this->writeStamp($stampFile, $stamp);

                $result = $this->pruner->prune($current->baseDirectory, new ReportPruneOptionsDTO(
                    keepRuns: $this->keepRuns(),
                    keepDays: $this->keepDays(),
                    graceSeconds: $this->graceMinutes() * 60,
                    retention: $this->pruningEnabled(),
                    maxDeletions: self::MAX_DELETIONS_PER_SWEEP,
                    protectedReportDirectories: [$current->reportDirectory],
                    now: $now,
                ));

                if ($result->skippedReason !== null || $result->failed !== []) {
                    $this->reportSweepFailure($result->skippedReason ?? 'could not remove '.count($result->failed).' run directories, first: '.$result->failed[0]);

                    return;
                }

                $this->writeStamp($stampFile, ['lastSuccessAt' => $now, 'lastAttemptAt' => $now, 'backlog' => $result->truncated]);
            } finally {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        } catch (Throwable $e) {
            $this->reportSweepFailure($e->getMessage());
        }
    }

    /**
     * End a run: delete its runtime files, record the outcome, release its lock.
     *
     * @param  string  $state  How the run ended (`passed`, `failed`, `interrupted`).
     * @param  string|null  $targetDirectory  The target's working directory, to clear per-run state an older published JS harness wrote there.
     */
    public function finish(ReportRun $run, string $state, ?string $targetDirectory = null): void
    {
        try {
            $this->registry->close($run, $state);

            if ($targetDirectory !== null) {
                $this->removeLegacyTargetState($targetDirectory, $run->runId);
            }
        } catch (Throwable) {
            // Cleanup must never turn a finished run into a failure.
        }
    }

    /**
     * Test hook: forget that a sweep failure was already reported.
     *
     * @internal
     */
    public static function resetState(): void
    {
        self::$sweepFailureReported = false;
    }

    private function sweepIsDue(string $stampFile, int $now): bool
    {
        $stamp = $this->readStamp($stampFile);
        $last = max($stamp['lastSuccessAt'], $stamp['lastAttemptAt']);
        $interval = $this->intervalMinutes() * 60;

        if ($stamp['backlog'] && $stamp['lastSuccessAt'] === $last) {
            $interval = min($interval, self::BACKLOG_INTERVAL_SECONDS);
        }

        return $now - $last >= $interval;
    }

    /**
     * @return array{lastSuccessAt: int, lastAttemptAt: int, backlog: bool}
     */
    private function readStamp(string $stampFile): array
    {
        $stamp = ['lastSuccessAt' => 0, 'lastAttemptAt' => 0, 'backlog' => false];
        $raw = is_file($stampFile) ? @file_get_contents($stampFile) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($decoded)) {
            $stamp['lastSuccessAt'] = is_int($decoded['lastSuccessAt'] ?? null) ? $decoded['lastSuccessAt'] : 0;
            $stamp['lastAttemptAt'] = is_int($decoded['lastAttemptAt'] ?? null) ? $decoded['lastAttemptAt'] : 0;
            $stamp['backlog'] = ($decoded['backlog'] ?? false) === true;
        }

        return $stamp;
    }

    /**
     * @param  array{lastSuccessAt: int, lastAttemptAt: int, backlog: bool}  $stamp
     */
    private function writeStamp(string $stampFile, array $stamp): void
    {
        @file_put_contents($stampFile, json_encode($stamp, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function reportSweepFailure(string $reason): void
    {
        if (self::$sweepFailureReported) {
            return;
        }

        self::$sweepFailureReported = true;

        $message = "pest-e2e: report pruning skipped — {$reason}. Tests are unaffected; it will be retried later.";

        if ($this->diagnostic instanceof Closure) {
            ($this->diagnostic)($message);

            return;
        }

        fwrite(STDERR, "\n{$message}\n");
    }

    /**
     * Remove `<target dir>/.pest-e2e/<runId>/storageState.json` left by an older published harness.
     *
     * Only that one file is deleted, then the run's directory if it is empty.
     * Anything else found there is left alone.
     */
    private function removeLegacyTargetState(string $targetDirectory, string $runId): void
    {
        if (preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/', $runId) !== 1) {
            return;
        }

        $stateRoot = rtrim($targetDirectory, '/').'/'.self::LEGACY_TARGET_STATE_DIRECTORY;
        $runDirectory = $stateRoot.'/'.$runId;

        if (is_link($stateRoot) || is_link($runDirectory) || ! is_dir($runDirectory)) {
            return;
        }

        $storageState = $runDirectory.'/storageState.json';

        if (is_file($storageState) && ! is_link($storageState)) {
            @unlink($storageState);
        }

        @rmdir($runDirectory);
    }

    private function baseDir(): string
    {
        return ReportPathPolicy::resolve(
            $this->explicitBaseDir(),
            $this->defaultBaseDirResolver ?? static fn (): string => function_exists('storage_path')
                ? storage_path('framework/testing/pest-e2e')
                : '',
            self::FALLBACK_NAMESPACE,
        );
    }

    private function explicitBaseDir(): ?string
    {
        if (! function_exists('config')) {
            return null;
        }

        $configured = config('pest-e2e.reports.base_dir');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        // Backwards-compat: consumers who published `config/pest-e2e.php`
        // before this fix have the literal legacy default value
        // (`storage_path('framework/testing/pest-e2e')`) baked into their
        // config. Treat that as "no explicit directive" so they receive the
        // fallback behaviour without editing their published config. Any
        // genuinely custom path stays strict.
        if ($this->matchesLegacyShippedDefault($configured)) {
            return null;
        }

        return $configured;
    }

    private function matchesLegacyShippedDefault(string $path): bool
    {
        if (! function_exists('storage_path')) {
            return false;
        }

        try {
            $legacy = storage_path('framework/testing/pest-e2e');
        } catch (Throwable) {
            return false;
        }

        if ($legacy === '') {
            return false;
        }

        return $this->normalizeForComparison($path) === $this->normalizeForComparison($legacy);
    }

    private function normalizeForComparison(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Resolve and create the run directory. Returns [$baseDir, $runDir].
     *
     * @return array{string, string}
     */
    private function prepareRunDirectory(string $target, string $runId): array
    {
        $baseDir = $this->baseDir();
        $runDir = $this->runDirectory($baseDir, $target, $runId);
        $this->ensureDirectory($runDir);

        return [$baseDir, $runDir];
    }

    private function runDirectory(string $baseDir, string $target, string $runId): string
    {
        return rtrim($baseDir, '/').'/'.$this->safeSegment($target).'/'.$this->safeSegment($runId);
    }

    private function safeSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value);
        $segment = is_string($segment) ? trim($segment, '.-') : '';

        return $segment !== '' ? $segment : 'run';
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create Pest E2E report directory: {$dir}");
        }
    }

    /**
     * Write the run marker. If the first attempt fails because the resolved
     * base directory is not writable by the current process — typically the
     * mixed-UID `vendor/orchestra/testbench-core/laravel/storage/…` case —
     * fall back to the policy's deterministic per-(uid, project) directory
     * and retry exactly once. Any other write failure, or a second failure
     * against the fallback, surfaces as the original RuntimeException.
     *
     * @return array{string, string} the [baseDir, runDir] actually used
     */
    private function writeRunMarkerWithFallback(string $baseDir, string $runDir, string $target, string $runId): array
    {
        $marker = $this->buildMarker($target, $runId);

        if ($this->attemptWriteMarker($runDir, $marker)) {
            return [$baseDir, $runDir];
        }

        // Only escape to the fallback for the implicit-default case. An
        // explicit `config('pest-e2e.reports.base_dir')` is a directive — we
        // preserve its failure semantics rather than silently diverting.
        if ($this->explicitBaseDir() !== null) {
            throw new RuntimeException("Unable to write Pest E2E report marker: {$runDir}/".self::RUN_MARKER);
        }

        $fallbackBase = ReportPathPolicy::fallbackDirectory(self::FALLBACK_NAMESPACE);

        if ($fallbackBase === $baseDir) {
            // Already using the fallback — nothing left to try.
            throw new RuntimeException("Unable to write Pest E2E report marker: {$runDir}/".self::RUN_MARKER);
        }

        ReportPathPolicy::ensureDirectory($fallbackBase);
        $fallbackRun = $this->runDirectory($fallbackBase, $target, $runId);
        $this->ensureDirectory($fallbackRun);

        if (! $this->attemptWriteMarker($fallbackRun, $marker)) {
            throw new RuntimeException("Unable to write Pest E2E report marker: {$fallbackRun}/".self::RUN_MARKER);
        }

        return [$fallbackBase, $fallbackRun];
    }

    private function attemptWriteMarker(string $runDir, string $marker): bool
    {
        return @file_put_contents($runDir.'/'.self::RUN_MARKER, $marker, LOCK_EX) !== false;
    }

    private function buildMarker(string $target, string $runId): string
    {
        return json_encode([
            'target' => $target,
            'runId' => $runId,
            'createdAt' => time(),
        ], JSON_THROW_ON_ERROR);
    }

    private function pruningEnabled(): bool
    {
        if (! function_exists('config')) {
            return true;
        }

        return (bool) config('pest-e2e.reports.prune.enabled', true);
    }

    private function keepRuns(): int
    {
        $value = function_exists('config') ? config('pest-e2e.reports.prune.keep_runs', 500) : 500;

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 500;
    }

    private function keepDays(): int
    {
        $value = function_exists('config') ? config('pest-e2e.reports.prune.keep_days', 7) : 7;

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 7;
    }

    private function graceMinutes(): int
    {
        return $this->nonNegativeInt('pest-e2e.reports.prune.grace_minutes', 60);
    }

    private function intervalMinutes(): int
    {
        return $this->nonNegativeInt('pest-e2e.reports.prune.interval_minutes', 15);
    }

    private function nonNegativeInt(string $key, int $default): int
    {
        $value = function_exists('config') ? config($key, $default) : $default;

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return $default;
    }
}
