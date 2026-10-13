<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Recursive directory removal that never leaves the tree it was given.
 *
 * Symbolic links are removed as links; what they point at is never opened,
 * listed or deleted.
 *
 * @internal
 */
final class SafeDirectoryRemover
{
    /**
     * Remove a directory tree, but only when it is a real directory strictly inside $root.
     *
     * Every deletion pest-e2e performs goes through here, so nothing outside
     * a directory it owns can be removed, whatever path a caller computes.
     * Returns true when nothing is left at the path.
     */
    public static function removeTreeInside(string $directory, string $root): bool
    {
        if (! file_exists($directory) && ! is_link($directory)) {
            return true;
        }

        if (! self::isInside($directory, $root)) {
            return false;
        }

        return self::removeTree($directory);
    }

    private static function removeTree(string $directory): bool
    {
        if (is_link($directory)) {
            return false;
        }

        if (! is_dir($directory)) {
            return ! file_exists($directory);
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo) {
                    continue;
                }

                $path = $file->getPathname();

                if ($file->isLink() || ! $file->isDir()) {
                    @unlink($path);
                } else {
                    @rmdir($path);
                }
            }
        } catch (Throwable) {
            return false;
        }

        @rmdir($directory);

        return ! file_exists($directory);
    }

    /**
     * True when $path is a real directory located strictly inside $root.
     */
    public static function isInside(string $path, string $root): bool
    {
        if (is_link($path)) {
            return false;
        }

        $realPath = @realpath($path);
        $realRoot = @realpath($root);

        if (! is_string($realPath) || ! is_string($realRoot) || $realPath === $realRoot) {
            return false;
        }

        return str_starts_with($realPath, rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /**
     * Why a tree must not be removed automatically, or null when it only holds plain files and directories.
     */
    public static function unexpectedContent(string $directory): ?string
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo) {
                    continue;
                }

                if ($file->isLink()) {
                    return 'contains a symbolic link';
                }

                if (! $file->isDir() && ! $file->isFile()) {
                    return 'contains a special file';
                }
            }
        } catch (Throwable) {
            return 'could not be read';
        }

        return null;
    }
}
