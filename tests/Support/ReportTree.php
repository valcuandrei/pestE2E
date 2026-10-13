<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Support;

use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\ReportRun;
use ValcuAndrei\PestE2E\Support\ReportRunRegistry;
use ValcuAndrei\PestE2E\Support\SafeDirectoryRemover;

/**
 * Builds report trees for pruning tests: finished, active, abandoned and unmarked runs of a chosen age.
 */
final class ReportTree
{
    public function __construct(public readonly string $base)
    {
        config()->set('pest-e2e.reports.base_dir', $base);
    }

    public static function create(string $prefix = 'pest-e2e-prune-test-'): self
    {
        return new self(TemporaryDirectories::create($prefix));
    }

    public function manager(): ReportDirectoryManager
    {
        return new ReportDirectoryManager;
    }

    /**
     * A run that completed $ageSeconds ago and left a report behind.
     */
    public function finished(string $runId, int $ageSeconds, string $target = 'frontend'): string
    {
        $run = $this->manager()->begin($target, $runId);
        $this->playwrightWritesOutput($run->reportDirectory);
        $this->manager()->finish($run, 'passed');
        $this->age($run, $ageSeconds);

        return $run->reportDirectory;
    }

    /**
     * A run in progress: the returned handle holds its lock until released.
     */
    public function active(string $runId, int $ageSeconds = 0, string $target = 'frontend'): ReportRun
    {
        $run = $this->manager()->begin($target, $runId);
        $this->playwrightWritesOutput($run->reportDirectory);
        file_put_contents($run->runtimeDirectory.'/storageState.json', '{"cookies":[{"name":"laravel_session","value":"secret"}],"origins":[]}');
        $this->age($run, $ageSeconds);

        return $run;
    }

    /**
     * A run whose process died: still recorded as running, lock gone, runtime files left behind.
     */
    public function abandoned(string $runId, int $ageSeconds, string $target = 'frontend'): ReportRun
    {
        $run = $this->active($runId, $ageSeconds, $target);
        $run->releaseLock();

        return $run;
    }

    /**
     * A directory beside the runs with no proof of ownership, shaped like an old Playwright output directory.
     */
    public function unmarked(string $name, int $ageSeconds, string $target = 'frontend', bool $playwrightOutput = true): string
    {
        $directory = $this->base.'/'.$target.'/'.$name;
        mkdir($directory, 0700, true);

        if ($playwrightOutput) {
            file_put_contents($directory.'/.last-run.json', '{"status":"passed","failedTests":[]}');
            touch($directory.'/.last-run.json', time() - $ageSeconds);
        }

        touch($directory, time() - $ageSeconds);

        return $directory;
    }

    /**
     * A run directory in the layout of earlier versions: marker file inside it, no registry record.
     */
    public function legacyMarked(string $name, int $ageSeconds, string $target = 'frontend'): string
    {
        $directory = $this->base.'/'.$target.'/'.$name;
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/.pest-e2e-run', '{}');
        touch($directory.'/.pest-e2e-run', time() - $ageSeconds);

        return $directory;
    }

    /**
     * What Playwright does to its --output directory: empty it, then write its own files.
     */
    public function playwrightWritesOutput(string $reportDirectory): void
    {
        SafeDirectoryRemover::removeTreeInside($reportDirectory, $this->base);
        mkdir($reportDirectory, 0700, true);
        file_put_contents($reportDirectory.'/.last-run.json', '{"status":"passed","failedTests":[]}');
        mkdir($reportDirectory.'/spec-test-chromium');
        file_put_contents($reportDirectory.'/spec-test-chromium/trace.zip', 'trace');
    }

    public function record(ReportRun $run): string
    {
        return $run->stateDirectory.'/'.ReportRunRegistry::RECORD_FILE;
    }

    public function stateRoot(): string
    {
        return ReportRunRegistry::stateRoot($this->base);
    }

    /**
     * Remove the tree. Only works for a tree made by {@see create()}; see {@see TemporaryDirectories::remove()}.
     */
    public function destroy(): void
    {
        TemporaryDirectories::remove($this->base);
    }

    private function age(ReportRun $run, int $ageSeconds): void
    {
        touch($this->record($run), time() - $ageSeconds);
    }
}
