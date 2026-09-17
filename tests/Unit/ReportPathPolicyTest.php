<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\ReportPathPolicy;

beforeEach(function (): void {
    $this->originalBasePath = null;

    if (function_exists('app')) {
        try {
            $app = app();

            if (method_exists($app, 'basePath')) {
                $this->originalBasePath = $app->basePath();
            }
        } catch (Throwable) {
            // Container may not be booted; nothing to snapshot.
        }
    }
});

afterEach(function (): void {
    foreach (($this->tempDirs ?? []) as $dir) {
        if (! is_dir($dir)) {
            continue;
        }

        @chmod($dir, 0700);
        removePolicyDirectory($dir);
    }

    if (isset($this->originalBasePath) && is_string($this->originalBasePath) && $this->originalBasePath !== '') {
        try {
            $app = app();

            if (method_exists($app, 'setBasePath')) {
                $app->setBasePath($this->originalBasePath);
            }
        } catch (Throwable) {
            // Ignore restoration failures — irrelevant to test outcomes.
        }
    }
});

it('returns an explicit path unchanged even when it does not exist', function (): void {
    $explicit = sys_get_temp_dir().'/pest-e2e-policy-explicit-'.uniqid();

    $resolved = ReportPathPolicy::resolve($explicit, static fn (): string => '/should/not/be/used', 'ns');

    expect($resolved)->toBe($explicit)
        ->and(is_dir($resolved))->toBeFalse();
});

it('returns the default preferred path when it is writable', function (): void {
    $preferred = sys_get_temp_dir().'/pest-e2e-policy-preferred-'.uniqid();
    mkdir($preferred, 0700, true);
    $this->tempDirs[] = $preferred;

    $resolved = ReportPathPolicy::resolve(null, static fn (): string => $preferred, 'ns');

    expect($resolved)->toBe($preferred);
});

it('falls back to a deterministic per-user private directory when the preferred path is not writable', function (): void {
    $preferred = sys_get_temp_dir().'/pest-e2e-policy-locked-'.uniqid();
    mkdir($preferred, 0500, true);
    $this->tempDirs[] = $preferred;

    $resolved = ReportPathPolicy::resolve(null, static fn (): string => $preferred.'/nested', 'pest-e2e');

    expect($resolved)->not->toBe($preferred.'/nested')
        ->and($resolved)->toStartWith(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'pest-e2e-')
        ->and($resolved)->not->toContain($preferred);
});

it('returns the same fallback path for two callers in the same project as the same user', function (): void {
    $first = ReportPathPolicy::fallbackDirectory('pest-e2e');
    $second = ReportPathPolicy::fallbackDirectory('pest-e2e');

    expect($first)->toBe($second)
        ->and($first)->toContain('pest-e2e-');
});

it('returns different fallback paths for the same user across different project roots', function (): void {
    $projectA = sys_get_temp_dir().'/pest-e2e-policy-project-a-'.uniqid();
    $projectB = sys_get_temp_dir().'/pest-e2e-policy-project-b-'.uniqid();
    mkdir($projectA, 0700, true);
    mkdir($projectB, 0700, true);
    $this->tempDirs[] = $projectA;
    $this->tempDirs[] = $projectB;

    $app = app();

    $app->setBasePath($projectA);
    $pathA = ReportPathPolicy::fallbackDirectory('pest-e2e');

    $app->setBasePath($projectB);
    $pathB = ReportPathPolicy::fallbackDirectory('pest-e2e');

    expect($pathA)->not->toBe($pathB)
        ->and($pathA)->toContain('pest-e2e-')
        ->and($pathB)->toContain('pest-e2e-');
});

it('resolves the same fallback path regardless of the current working directory', function (): void {
    // Same project, same user, but CWD shifts mid-run. Historic behaviour
    // (`getcwd()`-based fingerprint) produced two different fallback roots
    // and split pruning/retention across them; the fix hashes the normalized
    // application base path instead, so CWD is irrelevant.
    $subdir = sys_get_temp_dir().'/pest-e2e-policy-cwd-'.uniqid();
    mkdir($subdir, 0700, true);
    $this->tempDirs[] = $subdir;

    $originalCwd = getcwd();

    $before = ReportPathPolicy::fallbackDirectory('pest-e2e');

    try {
        chdir($subdir);
        $afterChdir = ReportPathPolicy::fallbackDirectory('pest-e2e');
    } finally {
        if ($originalCwd !== false) {
            chdir($originalCwd);
        }
    }

    expect($afterChdir)->toBe($before);
});

it('scopes the fallback path by namespace so distinct on-disk stores never collide', function (): void {
    $reports = ReportPathPolicy::fallbackDirectory('pest-e2e');
    $agentOutput = ReportPathPolicy::fallbackDirectory('pest-e2e-agent-output');

    expect($reports)->not->toBe($agentOutput);
});

it('creates the fallback directory with owner-only permissions when it does not exist', function (): void {
    $target = sys_get_temp_dir().'/pest-e2e-policy-ensure-'.uniqid();
    $this->tempDirs[] = $target;

    expect(ReportPathPolicy::ensureDirectory($target))->toBeTrue()
        ->and(is_dir($target))->toBeTrue();

    $mode = fileperms($target) & 0777;
    expect($mode)->toBe(0700);
});

it('treats a resolver return value that throws as if there were no preferred path', function (): void {
    $resolved = ReportPathPolicy::resolve(
        null,
        static function (): string {
            throw new RuntimeException('storage_path unavailable');
        },
        'pest-e2e',
    );

    expect($resolved)->toStartWith(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'pest-e2e-');
});

it('returns a stable non-empty user token on POSIX and non-POSIX PHP builds', function (): void {
    // effectiveUserId() must never throw on any PHP build (posix, non-posix,
    // Windows, disabled-getmyuid); worst case it returns the literal 'nouid'
    // so the fallback path remains valid.
    $token = ReportPathPolicy::effectiveUserId();

    expect($token)->toBeString()
        ->and($token)->not->toBe('');

    if (function_exists('posix_geteuid')) {
        expect($token)->toBe((string) posix_geteuid());
    } elseif (function_exists('getmyuid')) {
        $uid = getmyuid();

        if ($uid !== false && $uid >= 0) {
            expect($token)->toBe((string) $uid);
        } else {
            expect($token)->toBe('nouid');
        }
    } else {
        expect($token)->toBe('nouid');
    }
});

it('produces a safe fallback directory even when the user token is the fallback literal', function (): void {
    // Simulate the non-POSIX branch behaviour end-to-end: the fallback path
    // must still be a well-formed writable-parent path even if effectiveUserId
    // ever returns 'nouid'.
    $token = ReportPathPolicy::effectiveUserId();
    $fallback = ReportPathPolicy::fallbackDirectory('pest-e2e');

    expect($fallback)->toContain('-'.$token.'-')
        ->and(dirname($fallback))->toBe(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR));
});

function removePolicyDirectory(string $dir): void
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
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($dir);
}
