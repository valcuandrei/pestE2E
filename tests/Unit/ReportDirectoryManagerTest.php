<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;

afterEach(function (): void {
    if (isset($this->reportBaseDir)) {
        removeReportDirectory($this->reportBaseDir);
    }
});

it('creates a target and run scoped report directory', function (): void {
    $this->reportBaseDir = sys_get_temp_dir().'/pest-e2e-report-dir-'.uniqid();
    config()->set('pest-e2e.reports.base_dir', $this->reportBaseDir);
    config()->set('pest-e2e.reports.prune.enabled', false);

    $dir = (new ReportDirectoryManager)->prepare('front/end', 'run:123');

    expect($dir)->toBe($this->reportBaseDir.'/front-end/run-123')
        ->and(is_dir($dir))->toBeTrue()
        ->and(is_file($dir.'/.pest-e2e-run'))->toBeTrue();
});

it('prunes old marked run directories without deleting the current run or unmarked directories', function (): void {
    $this->reportBaseDir = sys_get_temp_dir().'/pest-e2e-report-prune-'.uniqid();
    config()->set('pest-e2e.reports.base_dir', $this->reportBaseDir);
    config()->set('pest-e2e.reports.prune.enabled', true);
    config()->set('pest-e2e.reports.prune.keep_runs', 1);
    config()->set('pest-e2e.reports.prune.keep_days', 0);

    $manager = new ReportDirectoryManager;
    $oldRun = $manager->prepare('frontend', 'old-run');
    $unmarked = $this->reportBaseDir.'/frontend/manual-not-a-run';
    mkdir($unmarked, 0775, true);
    file_put_contents($unmarked.'/keep.txt', 'keep');

    sleep(1);

    $currentRun = $manager->prepare('frontend', 'current-run');

    expect(is_dir($currentRun))->toBeTrue()
        ->and(is_dir($oldRun))->toBeFalse()
        ->and(is_dir($unmarked))->toBeTrue();
});

it('never prunes the current run directory even when it is older than the configured age', function (): void {
    $this->reportBaseDir = sys_get_temp_dir().'/pest-e2e-report-current-'.uniqid();
    config()->set('pest-e2e.reports.base_dir', $this->reportBaseDir);
    config()->set('pest-e2e.reports.prune.enabled', true);
    config()->set('pest-e2e.reports.prune.keep_runs', 0);
    config()->set('pest-e2e.reports.prune.keep_days', 1);

    $manager = new ReportDirectoryManager;
    $currentRun = $manager->prepare('frontend', 'current-run');

    touch($currentRun.'/.pest-e2e-run', time() - 86400 * 10);

    $sameCurrentRun = $manager->prepare('frontend', 'current-run');

    expect($sameCurrentRun)->toBe($currentRun)
        ->and(is_dir($currentRun))->toBeTrue();
});

function removeReportDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($dir);
}
