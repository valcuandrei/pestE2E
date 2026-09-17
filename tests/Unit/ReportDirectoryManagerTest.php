<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\ReportPathPolicy;

afterEach(function (): void {
    if (isset($this->reportBaseDir)) {
        removeReportDirectory($this->reportBaseDir);
    }

    foreach (($this->tempFallbackDirs ?? []) as $dir) {
        removeReportDirectory($dir);
    }
});

it('resolves a run directory path without creating it', function (): void {
    $this->reportBaseDir = sys_get_temp_dir().'/pest-e2e-report-resolve-'.uniqid();
    config()->set('pest-e2e.reports.base_dir', $this->reportBaseDir);

    $dir = (new ReportDirectoryManager)->resolveRunDirectory('frontend', 'run-123');

    expect($dir)->toBe($this->reportBaseDir.'/frontend/run-123')
        ->and(is_dir($dir))->toBeFalse();
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

it('uses the injected default when no explicit config is set and the default is writable', function (): void {
    $writablePreferred = sys_get_temp_dir().'/pest-e2e-report-writable-preferred-'.uniqid();
    mkdir($writablePreferred, 0700, true);
    $this->reportBaseDir = $writablePreferred;

    config()->set('pest-e2e.reports.base_dir', null);
    config()->set('pest-e2e.reports.prune.enabled', false);

    $manager = new ReportDirectoryManager(static fn (): string => $writablePreferred);
    $dir = $manager->prepare('frontend', 'run-a');

    expect($dir)->toBe($writablePreferred.'/frontend/run-a')
        ->and(is_file($dir.'/.pest-e2e-run'))->toBeTrue();
});

it('falls back to the policy default when the implicit-default resolver returns an unwritable path', function (): void {
    // 0500 on a directory we own means neither owner nor group can create
    // entries; is_writable() reports false; ReportPathPolicy diverts to the
    // fallback root under sys_get_temp_dir().
    $locked = sys_get_temp_dir().'/pest-e2e-report-implicit-locked-'.uniqid();
    mkdir($locked, 0500, true);
    $this->reportBaseDir = $locked;

    config()->set('pest-e2e.reports.base_dir', null);
    config()->set('pest-e2e.reports.prune.enabled', false);

    $manager = new ReportDirectoryManager(static fn (): string => $locked.'/framework/testing/pest-e2e');
    $dir = $manager->prepare('frontend', 'run-b');

    expect($dir)->not->toStartWith($locked)
        ->and($dir)->toContain('pest-e2e-')
        ->and($dir)->toEndWith('/frontend/run-b')
        ->and(is_file($dir.'/.pest-e2e-run'))->toBeTrue();

    @chmod($locked, 0700);
    // Also clean the fallback we wrote into so it doesn't pollute other runs.
    $fallbackRoot = substr($dir, 0, (int) strpos($dir, '/frontend/'));
    if ($fallbackRoot !== '') {
        $this->tempFallbackDirs[] = $fallbackRoot;
    }
});

it('throws without falling back when an explicit unwritable base_dir is configured', function (): void {
    $lockedExplicit = sys_get_temp_dir().'/pest-e2e-report-explicit-locked-'.uniqid();
    mkdir($lockedExplicit, 0500, true);
    $this->reportBaseDir = $lockedExplicit;

    config()->set('pest-e2e.reports.base_dir', $lockedExplicit);
    config()->set('pest-e2e.reports.prune.enabled', false);

    $manager = new ReportDirectoryManager;

    expect(fn () => $manager->prepare('frontend', 'run-c'))
        ->toThrow(RuntimeException::class, 'Unable');

    @chmod($lockedExplicit, 0700);
});

it('retries the marker write into the fallback exactly once when the implicit default fails at write-time', function (): void {
    // Setup: pre-create the run directory with 0500 mode via a two-step
    // mkdir (traversable parents, unwritable leaf) so the first
    // file_put_contents on the marker file fails and the manager's retry
    // fires into the policy fallback.
    $preferredRoot = sys_get_temp_dir().'/pest-e2e-report-retry-implicit-'.uniqid();
    mkdir($preferredRoot, 0700, true);
    mkdir($preferredRoot.'/frontend', 0700);
    mkdir($preferredRoot.'/frontend/run-d', 0500);
    $this->reportBaseDir = $preferredRoot;

    config()->set('pest-e2e.reports.base_dir', null);
    config()->set('pest-e2e.reports.prune.enabled', false);

    $manager = new ReportDirectoryManager(static fn (): string => $preferredRoot);
    $dir = $manager->prepare('frontend', 'run-d');

    expect($dir)->not->toStartWith($preferredRoot)
        ->and($dir)->toContain('pest-e2e-')
        ->and($dir)->toEndWith('/frontend/run-d')
        ->and(is_file($dir.'/.pest-e2e-run'))->toBeTrue();

    @chmod($preferredRoot.'/frontend/run-d', 0700);
    $fallbackRoot = substr($dir, 0, (int) strpos($dir, '/frontend/'));
    if ($fallbackRoot !== '') {
        $this->tempFallbackDirs[] = $fallbackRoot;
    }
});

it('treats the legacy shipped default path as implicit and falls back when it is not writable', function (): void {
    // Consumers who published `config/pest-e2e.php` on an earlier release
    // have `storage_path('framework/testing/pest-e2e')` baked in as a
    // string literal. That value looks explicit but is really just the old
    // shipped default. It must be recognised and allowed to fall back so
    // those consumers pick up the fix without editing their config.
    $legacy = storage_path('framework/testing/pest-e2e');

    // Poison the legacy default so its parent can't be written into.
    $lockedRoot = sys_get_temp_dir().'/pest-e2e-report-legacy-locked-'.uniqid();
    mkdir($lockedRoot, 0500, true);
    $this->reportBaseDir = $lockedRoot;

    // Configure the exact literal legacy default value as if it had been
    // baked into a published config file.
    config()->set('pest-e2e.reports.base_dir', $legacy);
    config()->set('pest-e2e.reports.prune.enabled', false);

    // Ask the manager to treat the legacy value as its preferred default and
    // ensure that even though the legacy filesystem tree is locked, we still
    // succeed via the policy fallback.
    $manager = new ReportDirectoryManager(static fn (): string => $lockedRoot.'/framework/testing/pest-e2e');
    $dir = $manager->prepare('frontend', 'run-legacy');

    expect($dir)->not->toStartWith($legacy)
        ->and($dir)->toContain('pest-e2e-')
        ->and($dir)->toEndWith('/frontend/run-legacy')
        ->and(is_file($dir.'/.pest-e2e-run'))->toBeTrue();

    @chmod($lockedRoot, 0700);
    $fallbackRoot = substr($dir, 0, (int) strpos($dir, '/frontend/'));
    if ($fallbackRoot !== '') {
        $this->tempFallbackDirs[] = $fallbackRoot;
    }
});

it('preserves strict behaviour for a genuinely custom explicit path that is not the legacy default', function (): void {
    // A custom explicit path that is unwritable must still surface the
    // RuntimeException — no automatic fallback for real user directives.
    $lockedCustom = sys_get_temp_dir().'/pest-e2e-report-custom-locked-'.uniqid();
    mkdir($lockedCustom, 0500, true);
    $this->reportBaseDir = $lockedCustom;

    config()->set('pest-e2e.reports.base_dir', $lockedCustom);
    config()->set('pest-e2e.reports.prune.enabled', false);

    $manager = new ReportDirectoryManager;

    expect(fn () => $manager->prepare('frontend', 'run-custom'))
        ->toThrow(RuntimeException::class, 'Unable');

    @chmod($lockedCustom, 0700);
});

it('resolves the same report base directory regardless of the current working directory', function (): void {
    // Historical bug: `getcwd()` fed the project fingerprint, so running pest
    // from a subdirectory produced a different fallback bucket. Regression
    // guard: the normalized application base path is what actually matters.
    $lockedRoot = sys_get_temp_dir().'/pest-e2e-report-cwd-'.uniqid();
    mkdir($lockedRoot, 0500, true);
    $this->reportBaseDir = $lockedRoot;

    config()->set('pest-e2e.reports.base_dir', null);
    config()->set('pest-e2e.reports.prune.enabled', false);

    $originalCwd = getcwd();

    $subdir = sys_get_temp_dir().'/pest-e2e-report-cwd-shift-'.uniqid();
    mkdir($subdir, 0700, true);
    $this->tempFallbackDirs[] = $subdir;

    try {
        $before = (new ReportDirectoryManager(static fn (): string => $lockedRoot.'/framework/testing/pest-e2e'))
            ->prepare('frontend', 'run-cwd-1');

        chdir($subdir);

        $afterChdir = (new ReportDirectoryManager(static fn (): string => $lockedRoot.'/framework/testing/pest-e2e'))
            ->prepare('frontend', 'run-cwd-2');
    } finally {
        if ($originalCwd !== false) {
            chdir($originalCwd);
        }
    }

    // Same fallback root under /tmp — different run directory suffixes.
    $rootBefore = substr($before, 0, (int) strpos($before, '/frontend/'));
    $rootAfter = substr($afterChdir, 0, (int) strpos($afterChdir, '/frontend/'));

    expect($rootAfter)->toBe($rootBefore);

    @chmod($lockedRoot, 0700);
    if ($rootBefore !== '') {
        $this->tempFallbackDirs[] = $rootBefore;
    }
});

it('surfaces the RuntimeException when both the preferred and the fallback directories reject the marker write', function (): void {
    // Pre-poison BOTH the preferred and the fallback location so no path
    // can accept the marker. The manager must not retry more than once.
    $preferredRoot = sys_get_temp_dir().'/pest-e2e-report-both-fail-'.uniqid();
    mkdir($preferredRoot, 0700, true);
    mkdir($preferredRoot.'/frontend', 0700);
    mkdir($preferredRoot.'/frontend/run-e', 0500);
    $this->reportBaseDir = $preferredRoot;

    $policyFallback = ReportPathPolicy::fallbackDirectory('pest-e2e');
    @mkdir($policyFallback, 0700, true);
    @mkdir($policyFallback.'/frontend', 0700);
    @mkdir($policyFallback.'/frontend/run-e', 0500);

    config()->set('pest-e2e.reports.base_dir', null);
    config()->set('pest-e2e.reports.prune.enabled', false);

    $manager = new ReportDirectoryManager(static fn (): string => $preferredRoot);

    expect(fn () => $manager->prepare('frontend', 'run-e'))
        ->toThrow(RuntimeException::class);

    @chmod($preferredRoot.'/frontend/run-e', 0700);
    @chmod($policyFallback.'/frontend/run-e', 0700);
    if (is_dir($policyFallback)) {
        $this->tempFallbackDirs[] = $policyFallback;
    }
});

function removeReportDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    @chmod($dir, 0700);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        @chmod($file->getPathname(), 0700);
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($dir);
}
