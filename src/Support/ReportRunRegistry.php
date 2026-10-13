<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Throwable;

/**
 * Sidecar registry of the runs pest-e2e owns inside a report base directory.
 *
 * Playwright empties its `--output` directory — the run's report directory —
 * before every run, so nothing stored there can prove ownership or show that
 * a run is in progress. The registry keeps that knowledge next to the report
 * tree instead:
 *
 *     <base>/<target>/<runId>/                       reports (Playwright --output)
 *     <base>/.pest-e2e-state/<target>/<runId>/run.json   ownership record, locked while the run is active
 *     <base>/.pest-e2e-state/<target>/<runId>/runtime/   private files deleted when the run ends
 *
 * @internal
 */
final class ReportRunRegistry
{
    public const string STATE_DIRECTORY = '.pest-e2e-state';

    public const string RECORD_FILE = 'run.json';

    public const string RUNTIME_DIRECTORY = 'runtime';

    public const string STATE_RUNNING = 'running';

    public static function stateRoot(string $baseDirectory): string
    {
        return rtrim($baseDirectory, '/').'/'.self::STATE_DIRECTORY;
    }

    public static function stateDirectory(string $baseDirectory, string $targetSegment, string $runSegment): string
    {
        return self::stateRoot($baseDirectory).'/'.$targetSegment.'/'.$runSegment;
    }

    /**
     * Register a run and take its active-run lock.
     *
     * Never throws: when the record cannot be created the run is returned
     * unregistered, which only means automatic pruning will not touch it.
     */
    public function open(string $baseDirectory, string $target, string $runId, string $targetSegment, string $runSegment, string $reportDirectory): ReportRun
    {
        $stateDirectory = self::stateDirectory($baseDirectory, $targetSegment, $runSegment);
        $runtimeDirectory = $stateDirectory.'/'.self::RUNTIME_DIRECTORY;

        try {
            if (! $this->ensurePrivateDirectory($runtimeDirectory)) {
                return new ReportRun($target, $runId, $baseDirectory, $reportDirectory);
            }

            $handle = @fopen($stateDirectory.'/'.self::RECORD_FILE, 'c+');

            if ($handle === false) {
                return new ReportRun($target, $runId, $baseDirectory, $reportDirectory);
            }

            if (! @flock($handle, LOCK_EX)) {
                @fclose($handle);

                return new ReportRun($target, $runId, $baseDirectory, $reportDirectory);
            }

            $this->writeRecord($handle, [
                'target' => $target,
                'runId' => $runId,
                'state' => self::STATE_RUNNING,
                'createdAt' => time(),
                'pid' => getmypid() ?: null,
            ]);

            return new ReportRun($target, $runId, $baseDirectory, $reportDirectory, $stateDirectory, $runtimeDirectory, $handle);
        } catch (Throwable) {
            return new ReportRun($target, $runId, $baseDirectory, $reportDirectory);
        }
    }

    /**
     * Record how the run ended, delete its runtime files and release its lock.
     */
    public function close(ReportRun $run, string $state): void
    {
        if ($run->runtimeDirectory !== null) {
            SafeDirectoryRemover::removeTreeInside($run->runtimeDirectory, self::stateRoot($run->baseDirectory));
        }

        $handle = $run->lock();

        if ($handle !== null && $run->stateDirectory !== null) {
            try {
                $this->writeRecord($handle, [
                    'target' => $run->target,
                    'runId' => $run->runId,
                    'state' => $state,
                    'finishedAt' => time(),
                ]);
            } catch (Throwable) {
                // The record is advisory; the lock release below is what matters.
            }
        }

        $run->releaseLock();
    }

    /**
     * Every registered run under a base directory, without reading the records.
     *
     * @return list<array{target: string, run: string, stateDirectory: string, recordedAt: int}>
     */
    public function registeredRuns(string $baseDirectory): array
    {
        $root = self::stateRoot($baseDirectory);
        $runs = [];

        foreach ($this->realSubdirectories($root) as $target) {
            foreach ($this->realSubdirectories($root.'/'.$target) as $run) {
                $stateDirectory = $root.'/'.$target.'/'.$run;
                $record = $stateDirectory.'/'.self::RECORD_FILE;

                if (! is_file($record) || is_link($record)) {
                    continue;
                }

                $runs[] = [
                    'target' => $target,
                    'run' => $run,
                    'stateDirectory' => $stateDirectory,
                    'recordedAt' => @filemtime($record) ?: 0,
                ];
            }
        }

        return $runs;
    }

    /**
     * Try to take a run's lock without waiting.
     *
     * @return resource|null The locked handle when the run is not active; null when another process holds it.
     */
    public function lockIfInactive(string $stateDirectory): mixed
    {
        $handle = @fopen($stateDirectory.'/'.self::RECORD_FILE, 'r+');

        if ($handle === false) {
            return null;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    public function unlock(mixed $handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Names of the direct subdirectories that are real directories, never symlinks.
     *
     * @return list<string>
     */
    public function realSubdirectories(string $directory): array
    {
        if (! is_dir($directory) || is_link($directory)) {
            return [];
        }

        $names = [];

        foreach (@scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.'/'.$name;

            if (is_dir($path) && ! is_link($path)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function ensurePrivateDirectory(string $directory): bool
    {
        if (is_link($directory)) {
            return false;
        }

        if (is_dir($directory)) {
            return true;
        }

        return @mkdir($directory, 0700, true) || is_dir($directory);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $record
     */
    private function writeRecord(mixed $handle, array $record): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($record, JSON_THROW_ON_ERROR));
        fflush($handle);
    }
}
