<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * Central path-resolution policy for pest-e2e's on-disk report/output stores.
 *
 * Two locations need writable directories that survive across Sail↔host runs:
 * - `ReportDirectoryManager` (report + run-marker tree)
 * - `AgentOutputIntent` / `AgentOutputAggregator` (agent-output batch marker
 *   + payloads)
 *
 * All three previously duplicated a "storage_path() or sys_get_temp_dir()"
 * resolve. That default lives inside
 * `vendor/orchestra/testbench-core/laravel/storage/…` during the package's own
 * test suite (and inside the host project's `storage/framework/testing/…`
 * during consumer runs), which means its writability depends on whoever
 * created those directories — not on the current pest runtime user. When they
 * differ (e.g. root-owned `vendor/` created by `docker exec` and pest run as
 * the host user, or a Sail ↔ WSL alternation, or a CI job that switches
 * users), every marker write throws and the whole suite fails.
 *
 * This class centralises the resolution so every call site uses identical
 * rules:
 *
 * 1. If the caller supplies an explicit path (non-null string), respect it.
 *    That is a directive from the user's config; no automatic fallback is
 *    applied. The caller receives it exactly as configured and any write
 *    failure surfaces as usual.
 * 2. Otherwise resolve the default preferred path (usually a
 *    `storage_path(...)` subdirectory).
 * 3. If the preferred path is writable — or can be created in a writable
 *    parent — use it.
 * 4. Otherwise fall back to a deterministic private directory that is
 *    scoped to both the current effective UID *and* the normalized project
 *    root, so unrelated projects cannot collide in report retention/pruning
 *    even when they share `/tmp`.
 *
 * @internal
 */
final class ReportPathPolicy
{
    /**
     * Resolve a directory usable by pest-e2e for reports/output.
     *
     * @param  callable(): string  $defaultResolver
     *                                               Returns the "preferred" default when the caller has no explicit
     *                                               configuration. Typically wraps `storage_path(...)`. May return an
     *                                               empty string to signal "no default available"; may throw when the
     *                                               Laravel container isn't booted — both are handled as
     *                                               "fall through to the deterministic fallback".
     * @param  string  $fallbackNamespace
     *                                     Distinguishes the on-disk stores in the shared fallback root
     *                                     (e.g. `pest-e2e` for reports, `pest-e2e-agent-output` for agent
     *                                     output). Must be a stable, filesystem-safe identifier.
     */
    public static function resolve(?string $explicit, callable $defaultResolver, string $fallbackNamespace): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $preferred = null;

        try {
            $candidate = $defaultResolver();

            if ($candidate !== '') {
                $preferred = $candidate;
            }
        } catch (\Throwable) {
            // Default resolver may throw when Laravel isn't booted; fall through
            // to the deterministic fallback below.
        }

        if ($preferred !== null && self::isWritable($preferred)) {
            return $preferred;
        }

        return self::fallbackDirectory($fallbackNamespace);
    }

    /**
     * Deterministic per-(uid, project) directory under the OS temp root.
     *
     * Same project + same user always resolves to the same path so pruning
     * / run-history semantics still work; different projects (even for the
     * same user) get distinct trees so they never collide in retention.
     *
     * The project component is derived from the normalized application base
     * path — not `getcwd()` — so running pest from a subdirectory of the
     * project (e.g. `cd tests/Browser && pest`) still resolves to the same
     * fallback root.
     */
    public static function fallbackDirectory(string $namespace): string
    {
        $namespace = self::safeSegment($namespace);
        $uid = self::effectiveUserId();
        $projectHash = self::projectFingerprint();

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$namespace
            .'-'.$uid
            .'-'.$projectHash;
    }

    /**
     * True iff the caller can write inside $dir — either because $dir already
     * exists and is writable, or because it doesn't exist but the closest
     * existing ancestor is writable (so we can `mkdir` our way in).
     */
    public static function isWritable(string $dir): bool
    {
        if ($dir === '') {
            return false;
        }

        if (is_dir($dir)) {
            return is_writable($dir);
        }

        $parent = dirname($dir);

        while ($parent !== dirname($parent) && ! is_dir($parent)) {
            $parent = dirname($parent);
        }

        return is_writable($parent);
    }

    /**
     * Ensure a directory exists with restrictive permissions.
     *
     * Uses 0700 (owner rwx only) so the fallback root under `sys_get_temp_dir()`
     * is not readable by other users on shared hosts. Existing directories are
     * left as-is.
     */
    public static function ensureDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        if (@mkdir($dir, 0700, true)) {
            return true;
        }

        return is_dir($dir);
    }

    /**
     * Stable per-project fingerprint.
     *
     * Prefers the normalized Laravel application base path so pest runs from
     * different subdirectories of the same project still hash to the same
     * bucket. Falls back through installed package location, then the current
     * working directory as a last resort. The chosen root is `realpath()`-ed
     * so symlinked project checkouts collapse to a single fingerprint.
     */
    public static function projectFingerprint(): string
    {
        $root = self::resolveProjectRoot();

        return substr(sha1($root), 0, 12);
    }

    /**
     * Effective user id as a string; portable across POSIX / non-POSIX builds.
     *
     * On POSIX PHP (Linux/macOS with `--enable-posix`), returns `posix_geteuid()`.
     * On Windows or minimal builds without ext-posix, falls back to
     * `getmyuid()`, which reads the owner UID of the currently executing
     * script and is available in every PHP build. If both are unavailable
     * (or `getmyuid()` returns false because the script cannot be stat'd),
     * returns the literal `'nouid'` — project isolation still works because
     * the project fingerprint alone is sufficient to keep unrelated projects
     * apart; the user token only guards against multi-tenant reuse of the
     * same fallback root.
     */
    public static function effectiveUserId(): string
    {
        if (function_exists('posix_geteuid')) {
            $uid = @posix_geteuid();

            if ($uid >= 0) {
                return (string) $uid;
            }
        }

        if (function_exists('getmyuid')) {
            $uid = getmyuid();

            if (is_int($uid) && $uid >= 0) {
                return (string) $uid;
            }
        }

        return 'nouid';
    }

    private static function resolveProjectRoot(): string
    {
        // Preferred: the Laravel application base path. Independent of the
        // shell's CWD, follows symlinks via realpath(), and correctly points
        // to the host project during test-suite runs (Testbench sets it up).
        if (function_exists('app')) {
            try {
                $base = (string) app()->basePath();

                if ($base !== '') {
                    return self::normalizePath($base);
                }
            } catch (\Throwable) {
                // Container not booted or basePath unavailable; fall through.
            }
        }

        // Second preference: the composer vendor directory that installed
        // this package. Deterministic per-project because each consumer has
        // its own vendor/ tree.
        $packageRoot = dirname(__DIR__, 2);
        $vendor = dirname($packageRoot, 2);

        if (is_dir($vendor.'/composer')) {
            return self::normalizePath(dirname($vendor));
        }

        // Last resort: current working directory. Not ideal (CWD can shift),
        // but a stable-enough fallback when neither Laravel nor a
        // recognisable composer install layout is available.
        $cwd = getcwd();

        if ($cwd !== false) {
            return self::normalizePath($cwd);
        }

        return $packageRoot;
    }

    private static function normalizePath(string $path): string
    {
        $real = @realpath($path);

        if (is_string($real)) {
            return $real;
        }

        $trimmed = rtrim($path, DIRECTORY_SEPARATOR);

        return $trimmed === '' ? $path : $trimmed;
    }

    private static function safeSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value);
        $segment = is_string($segment) ? trim($segment, '.-') : '';

        return $segment !== '' ? $segment : 'pest-e2e';
    }
}
