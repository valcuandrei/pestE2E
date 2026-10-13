<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Support;

use LogicException;
use ValcuAndrei\PestE2E\Support\ReportPathPolicy;

/**
 * Creates and removes the scratch directories tests work in.
 *
 * Removal is deliberately narrow. A directory is only removed when it is the
 * exact directory this class created in this process, is still a real
 * directory at the same real path, sits directly inside the system temp
 * directory, carries this suite's name pattern, and has nothing to do with
 * the application's real report directory. Anything else throws, so a test
 * that points cleanup at the wrong place fails loudly instead of deleting.
 *
 * No shell is involved; symbolic links are removed as links and never followed.
 */
final class TemporaryDirectories
{
    private const string NAME_PATTERN = '/^pest-e2e-[a-z0-9-]+-[0-9a-f]{12}$/';

    /**
     * Directories created by this process: path as returned => real path at creation.
     *
     * @var array<string, string>
     */
    private static array $created = [];

    public static function create(string $prefix): string
    {
        if (preg_match('/^pest-e2e-[a-z0-9-]+-$/', $prefix) !== 1) {
            throw new LogicException("Temporary directory prefix must look like 'pest-e2e-<name>-', got '{$prefix}'.");
        }

        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(6));

        if (! mkdir($path, 0700) || is_link($path)) {
            throw new LogicException("Could not create temporary directory {$path}.");
        }

        self::$created[$path] = (string) realpath($path);

        return $path;
    }

    /**
     * Remove a directory previously returned by {@see create()}.
     */
    public static function remove(string $path): void
    {
        $problem = self::removalProblem($path);

        if ($problem !== null) {
            throw new LogicException("Refusing to remove '{$path}': {$problem}.");
        }

        self::removeContents($path);
        rmdir($path);
        unset(self::$created[$path]);
    }

    /**
     * Remove one subtree of a directory created by {@see create()}.
     */
    public static function removeInside(string $path, string $ownedRoot): void
    {
        $problem = self::removalProblem($ownedRoot);

        if ($problem !== null) {
            throw new LogicException("Refusing to remove inside '{$ownedRoot}': {$problem}.");
        }

        $realPath = is_link($path) ? false : realpath($path);
        $realRoot = self::$created[$ownedRoot];

        if (! is_string($realPath) || ! is_dir($path) || ! str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR)) {
            throw new LogicException("Refusing to remove '{$path}': it is not a real directory inside '{$ownedRoot}'.");
        }

        self::removeContents($path);
        rmdir($path);
    }

    /**
     * Why a path must not be removed, or null when every check passes.
     */
    public static function removalProblem(string $path): ?string
    {
        if (! array_key_exists($path, self::$created)) {
            return 'it was not created by TemporaryDirectories in this process';
        }

        if (is_link($path)) {
            return 'it is now a symbolic link';
        }

        if (! is_dir($path)) {
            return 'it is not a directory';
        }

        $realPath = realpath($path);

        if ($realPath !== self::$created[$path]) {
            return 'it no longer resolves to the directory that was created';
        }

        if (dirname($realPath) !== realpath(sys_get_temp_dir())) {
            return 'it is not directly inside the system temp directory';
        }

        if (preg_match(self::NAME_PATTERN, basename($realPath)) !== 1) {
            return 'its name does not match the test fixture pattern';
        }

        foreach (self::realReportDirectories() as $reportDirectory) {
            if ($realPath === $reportDirectory || str_starts_with($reportDirectory, $realPath.DIRECTORY_SEPARATOR) || str_starts_with($realPath, $reportDirectory.DIRECTORY_SEPARATOR)) {
                return 'it overlaps the application\'s real report directory';
            }
        }

        return null;
    }

    /**
     * Regular files under a directory whose contents include $needle; links are not followed.
     *
     * @return list<string>
     */
    public static function filesContaining(string $directory, string $needle): array
    {
        $matches = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                array_push($matches, ...self::filesContaining($path, $needle));
            } elseif (str_contains((string) file_get_contents($path), $needle)) {
                $matches[] = $path;
            }
        }

        return $matches;
    }

    /**
     * Test hook for this class's own tests: pretend a path was created here.
     *
     * @internal
     */
    public static function registerForTesting(string $path, string $realPath): void
    {
        self::$created[$path] = $realPath;
    }

    /**
     * @internal
     */
    public static function forget(string $path): void
    {
        unset(self::$created[$path]);
    }

    /**
     * Where a real (non-test) run would keep reports: never a fixture.
     *
     * @return list<string>
     */
    private static function realReportDirectories(): array
    {
        $candidates = [ReportPathPolicy::fallbackDirectory('pest-e2e')];

        if (function_exists('storage_path')) {
            try {
                $candidates[] = storage_path('framework/testing/pest-e2e');
            } catch (\Throwable) {
                // No application booted; the fallback directory above still applies.
            }
        }

        return array_map(static fn (string $candidate): string => realpath($candidate) ?: $candidate, $candidates);
    }

    private static function removeContents(string $directory): void
    {
        @chmod($directory, 0700);

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_link($path) || ! is_dir($path)) {
                unlink($path);

                continue;
            }

            self::removeContents($path);
            rmdir($path);
        }
    }
}
