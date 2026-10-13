<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\ReportPathPolicy;
use ValcuAndrei\PestE2E\Tests\Support\TemporaryDirectories;

/*
 * The fixture-cleanup helper deletes recursively, so its own boundaries are tested:
 * it must only ever remove the exact directory it created.
 */

beforeEach(function (): void {
    $this->witness = TemporaryDirectories::create('pest-e2e-cleanup-witness-');
    file_put_contents($this->witness.'/precious.txt', 'must survive');
    $this->pretended = [];
});

afterEach(function (): void {
    foreach ($this->pretended as $path) {
        TemporaryDirectories::forget($path);
    }

    TemporaryDirectories::remove($this->witness);
});

it('removes a directory it created, including nested, read-only and linked entries', function (): void {
    $directory = TemporaryDirectories::create('pest-e2e-cleanup-subject-');
    mkdir($directory.'/a/b', 0700, true);
    file_put_contents($directory.'/a/b/file.txt', 'x');
    mkdir($directory.'/read-only', 0500);
    symlink($this->witness, $directory.'/a/link-to-witness');
    symlink($this->witness.'/precious.txt', $directory.'/link-to-file');

    TemporaryDirectories::remove($directory);

    expect(file_exists($directory))->toBeFalse()
        ->and(file_get_contents($this->witness.'/precious.txt'))->toBe('must survive');
});

it('cannot be used twice on the same directory', function (): void {
    $directory = TemporaryDirectories::create('pest-e2e-cleanup-subject-');
    TemporaryDirectories::remove($directory);

    expect(fn () => TemporaryDirectories::remove($directory))->toThrow(LogicException::class, 'was not created by TemporaryDirectories');
});

it('refuses a directory it did not create', function (): void {
    $foreign = sys_get_temp_dir().'/pest-e2e-cleanup-foreign-'.bin2hex(random_bytes(6));
    mkdir($foreign, 0700);
    file_put_contents($foreign.'/keep.txt', 'keep');

    expect(fn () => TemporaryDirectories::remove($foreign))->toThrow(LogicException::class, 'was not created by TemporaryDirectories')
        ->and(is_file($foreign.'/keep.txt'))->toBeTrue();

    unlink($foreign.'/keep.txt');
    rmdir($foreign);
});

it('refuses a created directory that has been replaced by a symbolic link', function (): void {
    $directory = TemporaryDirectories::create('pest-e2e-cleanup-subject-');
    rmdir($directory);
    symlink($this->witness, $directory);

    expect(fn () => TemporaryDirectories::remove($directory))->toThrow(LogicException::class, 'symbolic link')
        ->and(is_file($this->witness.'/precious.txt'))->toBeTrue();

    unlink($directory);
    TemporaryDirectories::forget($directory);
});

it('refuses a path that no longer resolves to the directory it created', function (): void {
    $this->pretended[] = $this->witness.'/nested';
    mkdir($this->witness.'/nested');
    TemporaryDirectories::registerForTesting($this->witness.'/nested', '/somewhere/else');

    expect(TemporaryDirectories::removalProblem($this->witness.'/nested'))->toContain('no longer resolves');
});

it('refuses a directory that is not directly inside the system temp directory', function (): void {
    $nested = $this->witness.'/pest-e2e-cleanup-nested-0123456789ab';
    mkdir($nested);
    $this->pretended[] = $nested;
    TemporaryDirectories::registerForTesting($nested, (string) realpath($nested));

    expect(fn () => TemporaryDirectories::remove($nested))->toThrow(LogicException::class, 'not directly inside the system temp directory')
        ->and(is_dir($nested))->toBeTrue();
});

it('refuses a directory whose name is not a fixture name', function (): void {
    $odd = sys_get_temp_dir().'/important-data-'.bin2hex(random_bytes(6));
    mkdir($odd, 0700);
    $this->pretended[] = $odd;
    TemporaryDirectories::registerForTesting($odd, (string) realpath($odd));

    expect(fn () => TemporaryDirectories::remove($odd))->toThrow(LogicException::class, 'does not match the test fixture pattern')
        ->and(is_dir($odd))->toBeTrue();

    rmdir($odd);
});

it('refuses the application\'s real report directory even when told it is a fixture', function (string $which): void {
    $real = $which === 'storage'
        ? storage_path('framework/testing/pest-e2e')
        : ReportPathPolicy::fallbackDirectory('pest-e2e');
    $existedBefore = is_dir($real);

    if (! $existedBefore) {
        mkdir($real, 0700, true);
    }

    file_put_contents($real.'/cleanup-guard-sentinel.txt', 'must survive');
    $this->pretended[] = $real;
    TemporaryDirectories::registerForTesting($real, (string) realpath($real));

    expect(fn () => TemporaryDirectories::remove($real))->toThrow(LogicException::class)
        ->and(is_file($real.'/cleanup-guard-sentinel.txt'))->toBeTrue();

    unlink($real.'/cleanup-guard-sentinel.txt');

    if (! $existedBefore) {
        rmdir($real);
    }
})->with(['storage', 'fallback']);

it('names the report directory as the reason when a fixture path would overlap it', function (): void {
    // A fallback report directory is the one real report location that does live directly in the temp directory.
    $real = ReportPathPolicy::fallbackDirectory('pest-e2e');
    $existedBefore = is_dir($real);

    if (! $existedBefore) {
        mkdir($real, 0700, true);
    }

    $this->pretended[] = $real;
    TemporaryDirectories::registerForTesting($real, (string) realpath($real));

    expect(TemporaryDirectories::removalProblem($real))->not->toBeNull();

    if (! $existedBefore) {
        rmdir($real);
    }
});

it('only removes subtrees that are real directories inside a directory it created', function (): void {
    $directory = TemporaryDirectories::create('pest-e2e-cleanup-subject-');
    mkdir($directory.'/inside/deeper', 0700, true);
    symlink($this->witness, $directory.'/link');

    expect(fn () => TemporaryDirectories::removeInside($this->witness, $directory))->toThrow(LogicException::class)
        ->and(fn () => TemporaryDirectories::removeInside($directory.'/link', $directory))->toThrow(LogicException::class)
        ->and(fn () => TemporaryDirectories::removeInside($directory, $directory))->toThrow(LogicException::class)
        ->and(fn () => TemporaryDirectories::removeInside($directory.'/inside/../../'.basename($this->witness), $directory))->toThrow(LogicException::class)
        ->and(is_file($this->witness.'/precious.txt'))->toBeTrue();

    TemporaryDirectories::removeInside($directory.'/inside', $directory);

    expect(is_dir($directory.'/inside'))->toBeFalse();

    TemporaryDirectories::remove($directory);
});

it('rejects prefixes that would not produce a fixture name', function (string $prefix): void {
    expect(fn () => TemporaryDirectories::create($prefix))->toThrow(LogicException::class);
})->with(['', 'reports-', 'pest-e2e-', '../pest-e2e-x-', 'pest-e2e-x', 'pest-e2e-X-']);
