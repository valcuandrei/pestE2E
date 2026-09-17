<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use RuntimeException;

/**
 * @internal
 */
final class ReportDirectoryManager
{
    private const RUN_MARKER = '.pest-e2e-run';

    private const FALLBACK_NAMESPACE = 'pest-e2e';

    /** @var (callable():string)|null */
    private $defaultBaseDirResolver;

    /**
     * @param  (callable():string)|null  $defaultBaseDirResolver
     *                                                            Test-only seam so unit tests can inject a controlled default path.
     *                                                            Production callers should always pass `null`, which uses the shipped
     *                                                            `storage_path('framework/testing/pest-e2e')` resolver.
     */
    public function __construct(?callable $defaultBaseDirResolver = null)
    {
        $this->defaultBaseDirResolver = $defaultBaseDirResolver;
    }

    public function resolveRunDirectory(string $target, string $runId): string
    {
        return $this->runDirectory($this->baseDir(), $target, $runId);
    }

    public function prepare(string $target, string $runId): string
    {
        [$baseDir, $runDir] = $this->prepareRunDirectory($target, $runId);
        [$baseDir, $runDir] = $this->writeRunMarkerWithFallback($baseDir, $runDir, $target, $runId);
        $this->prune($baseDir, $runDir);

        return $runDir;
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
        } catch (\Throwable) {
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

    private function prune(string $baseDir, string $currentRunDir): void
    {
        if (! $this->pruningEnabled()) {
            return;
        }

        $runs = $this->markedRunDirectories($baseDir);
        $currentRunDir = $this->normalizePath($currentRunDir);
        $keepRuns = $this->keepRuns();
        $keepDays = $this->keepDays();
        $oldestAllowed = $keepDays > 0 ? time() - ($keepDays * 86400) : null;

        usort(
            $runs,
            static fn (array $left, array $right): int => $right['mtime'] <=> $left['mtime']
        );

        foreach ($runs as $index => $run) {
            if ($run['path'] === $currentRunDir) {
                continue;
            }

            $tooManyRuns = $keepRuns > 0 && $index >= $keepRuns;
            $tooOld = $oldestAllowed !== null && $run['mtime'] < $oldestAllowed;

            if ($tooManyRuns || $tooOld) {
                $this->deleteDirectory($run['path']);
            }
        }
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
        $value = function_exists('config') ? config('pest-e2e.reports.prune.keep_runs', 50) : 50;

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 50;
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

    /**
     * @return list<array{path:string, mtime:int}>
     */
    private function markedRunDirectories(string $baseDir): array
    {
        if (! is_dir($baseDir)) {
            return [];
        }

        $runs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            if (! $file->isDir()) {
                continue;
            }

            $path = $this->normalizePath($file->getPathname());
            $marker = $path.'/'.self::RUN_MARKER;

            if (is_file($marker)) {
                $runs[] = [
                    'path' => $path,
                    'mtime' => (filemtime($marker) ?: filemtime($path)) ?: 0,
                ];
            }
        }

        return $runs;
    }

    private function normalizePath(string $path): string
    {
        return rtrim($path, '/');
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            $path = $file->getPathname();

            $file->isDir() ? @rmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
