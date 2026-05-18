<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * Detects Pest / Paratest parallel workers and derives per-worker resources.
 *
 * @internal
 */
final class ParallelWorker
{
    /**
     * Parallel worker token from TEST_TOKEN (Paratest / Pest --parallel).
     */
    public static function token(): ?string
    {
        $token = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? getenv('TEST_TOKEN');

        if (! is_string($token) && ! is_int($token)) {
            return null;
        }

        if ($token === '') {
            return null;
        }

        return (string) $token;
    }

    /**
     * Whether the current PHP process is a parallel test worker.
     */
    public static function isParallel(): bool
    {
        return self::token() !== null;
    }

    /**
     * Deterministic HTTP port for this worker (base + token).
     */
    public static function serverPort(?int $basePort = null): ?int
    {
        $token = self::token();

        if ($token === null) {
            return null;
        }

        $base = $basePort ?? self::basePortFromConfig();

        return $base + (int) $token;
    }

    /**
     * Per-worker cache key prefix (matches Laravel parallel file-cache naming).
     */
    public static function cachePrefix(?string $existing = null): ?string
    {
        $token = self::token();

        if ($token === null) {
            return null;
        }

        $prefix = "test_{$token}_";

        if ($existing !== null && $existing !== '') {
            return $prefix.$existing;
        }

        return $prefix;
    }

    /**
     * Worker-scoped database name (Laravel parallel testing convention).
     */
    public static function testDatabaseName(string $database): string
    {
        $token = self::token();

        if ($token === null) {
            return $database;
        }

        if (preg_match('/_test_'.preg_quote($token, '/').'$/', $database) === 1) {
            return $database;
        }

        if (preg_match('/_test_\d+$/', $database) === 1) {
            return $database;
        }

        return "{$database}_test_{$token}";
    }

    /**
     * Environment variables to pass into the managed Laravel server subprocess.
     *
     * @return array<string, string>
     */
    public static function serverEnvironment(): array
    {
        $token = self::token();

        if ($token === null) {
            return [];
        }

        $env = [
            'TEST_TOKEN' => $token,
            'LARAVEL_PARALLEL_TESTING' => '1',
            'PEST_E2E_PARALLEL' => '1',
        ];

        $cachePrefix = self::resolveWorkerCachePrefix();

        if ($cachePrefix !== null) {
            $env['CACHE_PREFIX'] = $cachePrefix;
        }

        $database = self::resolveWorkerDatabaseName();

        if ($database !== null) {
            $env['DB_DATABASE'] = $database;
        }

        return $env;
    }

    /**
     * Suffix for temp paths (Playwright output, params files).
     */
    public static function pathSuffix(): string
    {
        $token = self::token();

        return $token === null ? '' : '/worker-'.$token;
    }

    private static function basePortFromConfig(): int
    {
        if (function_exists('config')) {
            $configured = config('pest-e2e.parallel.base_port');

            if (is_int($configured)) {
                return $configured;
            }

            if (is_string($configured) && is_numeric($configured)) {
                return (int) $configured;
            }
        }

        $env = self::readEnvString('PEST_E2E_PARALLEL_BASE_PORT');

        if ($env !== null && is_numeric($env)) {
            return (int) $env;
        }

        return 8800;
    }

    private static function resolveWorkerDatabaseName(): ?string
    {
        if (function_exists('config')) {
            try {
                $default = config('database.default');

                if (is_string($default)) {
                    $database = config("database.connections.{$default}.database");

                    if (is_string($database) && $database !== '' && $database !== ':memory:') {
                        return self::testDatabaseName($database);
                    }
                }
            } catch (\Throwable) {
                // config may be unavailable outside a booted app
            }
        }

        $fromEnv = self::readEnvString('DB_DATABASE');

        if ($fromEnv === null || $fromEnv === '') {
            return null;
        }

        return self::testDatabaseName($fromEnv);
    }

    private static function resolveWorkerCachePrefix(): ?string
    {
        if (function_exists('config')) {
            try {
                $prefix = config('cache.prefix');

                if (is_string($prefix) && $prefix !== '') {
                    return $prefix;
                }
            } catch (\Throwable) {
                // config may be unavailable outside a booted app
            }
        }

        return self::cachePrefix(self::readEnvString('CACHE_PREFIX'));
    }

    private static function readEnvString(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        if ($value === '') {
            return null;
        }

        return (string) $value;
    }
}
