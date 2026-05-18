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

    public function resolveRunDirectory(string $target, string $runId): string
    {
        return $this->runDirectory($this->baseDir(), $target, $runId);
    }

    public function prepare(string $target, string $runId): string
    {
        $baseDir = $this->baseDir();

        $runDir = $this->runDirectory($baseDir, $target, $runId);
        $this->ensureDirectory($runDir);
        $this->writeRunMarker($runDir, $target, $runId);
        $this->prune($baseDir, $runDir);

        return $runDir;
    }

    private function baseDir(): string
    {
        if (function_exists('config')) {
            $configured = config('pest-e2e.reports.base_dir');

            if (is_string($configured) && $configured !== '') {
                return $configured;
            }
        }

        return function_exists('storage_path')
            ? storage_path('framework/testing/pest-e2e')
            : rtrim(sys_get_temp_dir(), '/').'/pest-e2e/reports';
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

    private function writeRunMarker(string $runDir, string $target, string $runId): void
    {
        $marker = json_encode([
            'target' => $target,
            'runId' => $runId,
            'createdAt' => time(),
        ], JSON_THROW_ON_ERROR);

        if (@file_put_contents($runDir.'/'.self::RUN_MARKER, $marker, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write Pest E2E report marker: {$runDir}/".self::RUN_MARKER);
        }
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
                    'mtime' => filemtime($marker) ?: filemtime($path) ?: 0,
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
