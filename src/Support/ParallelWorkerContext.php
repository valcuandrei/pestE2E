<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * Detects Pest / Paratest parallel workers and derives per-worker resources.
 *
 * @internal
 */
final class ParallelWorkerContext
{
    /**
     * Parallel worker token from TEST_TOKEN (Paratest / Pest --parallel).
     */
    public static function token(): ?string
    {
        $token = self::readTokenFromEnvironment();

        if ($token === null || $token === '') {
            return null;
        }

        return $token;
    }

    /**
     * Whether the current PHP process is a parallel test worker.
     */
    public static function isParallel(): bool
    {
        return self::token() !== null;
    }

    /**
     * Numeric worker token when TEST_TOKEN is a positive integer.
     */
    public static function numericToken(): ?int
    {
        $token = self::token();

        if ($token === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $token) !== 1) {
            return null;
        }

        return (int) $token;
    }

    /**
     * Deterministic HTTP port for this worker (base + token), or null when not parallel.
     */
    public static function serverPort(?int $basePort = null): ?int
    {
        if (! self::isParallel() || ! self::parallelPortOffsetEnabled()) {
            return null;
        }

        $numericToken = self::numericToken();

        if ($numericToken === null) {
            return null;
        }

        $base = $basePort ?? self::basePortFromConfig();

        return $base + $numericToken;
    }

    /**
     * Worker-specific application URL for the managed server and Playwright.
     */
    public static function appUrl(?string $host = null, ?int $basePort = null): string
    {
        $host ??= self::serverHostFromConfig();
        $base = $basePort ?? self::basePortFromConfig();
        $port = self::serverPort($base);

        if ($port === null) {
            return "http://{$host}";
        }

        return "http://{$host}:{$port}";
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

        foreach (self::resolveDatabaseEnvironment() as $key => $value) {
            $env[$key] = $value;
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

    public static function serverHostFromConfig(): string
    {
        if (function_exists('config')) {
            $configured = config('pest-e2e.server.host');

            if (is_string($configured) && $configured !== '') {
                return $configured;
            }
        }

        $env = self::readEnvString('PEST_E2E_SERVER_HOST');

        if ($env !== null) {
            return $env;
        }

        return '127.0.0.1';
    }

    public static function parallelPortOffsetEnabled(): bool
    {
        if (function_exists('config')) {
            $configured = config('pest-e2e.server.parallel_port_offset');

            if (is_bool($configured)) {
                return $configured;
            }
        }

        $env = self::readEnvString('PEST_E2E_SERVER_PARALLEL_PORT_OFFSET');

        if ($env === null) {
            return true;
        }

        return filter_var($env, FILTER_VALIDATE_BOOL);
    }

    public static function basePortFromConfig(): int
    {
        if (function_exists('config')) {
            $serverPort = config('pest-e2e.server.port');

            if (is_int($serverPort)) {
                return $serverPort;
            }

            if (is_string($serverPort) && is_numeric($serverPort)) {
                return (int) $serverPort;
            }

            $configured = config('pest-e2e.parallel.base_port');

            if (is_int($configured)) {
                return $configured;
            }

            if (is_string($configured) && is_numeric($configured)) {
                return (int) $configured;
            }
        }

        foreach (['PEST_E2E_SERVER_PORT', 'PEST_E2E_PARALLEL_BASE_PORT'] as $key) {
            $env = self::readEnvString($key);

            if ($env !== null && is_numeric($env)) {
                return (int) $env;
            }
        }

        return 8800;
    }

    /**
     * @return array<string, string>
     */
    private static function resolveDatabaseEnvironment(): array
    {
        $resolved = [];

        if (function_exists('config')) {
            try {
                $default = config('database.default');

                if (is_string($default) && $default !== '') {
                    $connection = config("database.connections.{$default}");

                    if (is_array($connection)) {
                        $fromConfig = self::databaseEnvFromConnection($default, $connection);

                        if (($fromConfig['DB_DATABASE'] ?? null) !== ':memory:') {
                            $resolved = $fromConfig;
                        }
                    }
                }
            } catch (\Throwable) {
                // config may be unavailable outside a booted app
            }
        }

        $keys = ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

        foreach ($keys as $key) {
            if (isset($resolved[$key])) {
                continue;
            }

            $fromEnv = self::readEnvString($key);

            if ($fromEnv === null) {
                continue;
            }

            $resolved[$key] = $key === 'DB_DATABASE'
                ? self::testDatabaseName($fromEnv)
                : $fromEnv;
        }

        if (isset($resolved['DB_DATABASE']) && $resolved['DB_DATABASE'] !== ':memory:') {
            $resolved['DB_DATABASE'] = self::testDatabaseName($resolved['DB_DATABASE']);
        }

        return $resolved;
    }

    /**
     * @param  array<mixed, mixed>  $connection
     * @return array<string, string>
     */
    private static function databaseEnvFromConnection(string $defaultConnection, array $connection): array
    {
        $env = [
            'DB_CONNECTION' => $defaultConnection,
        ];

        $map = [
            'DB_HOST' => 'host',
            'DB_PORT' => 'port',
            'DB_DATABASE' => 'database',
            'DB_USERNAME' => 'username',
            'DB_PASSWORD' => 'password',
        ];

        foreach ($map as $envKey => $configKey) {
            $value = $connection[$configKey] ?? null;

            if ($value === null) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $env[$envKey] = (string) $value;
        }

        if (isset($env['DB_DATABASE']) && $env['DB_DATABASE'] !== ':memory:') {
            $env['DB_DATABASE'] = self::testDatabaseName($env['DB_DATABASE']);
        }

        return $env;
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

    private static function readTokenFromEnvironment(): ?string
    {
        $candidates = [
            $_SERVER['TEST_TOKEN'] ?? null,
            $_ENV['TEST_TOKEN'] ?? null,
        ];

        if (function_exists('env')) {
            try {
                $candidates[] = env('TEST_TOKEN');
            } catch (\Throwable) {
                // env() may throw when the app is not booted
            }
        }

        $candidates[] = getenv('TEST_TOKEN');

        foreach ($candidates as $token) {
            if (! is_string($token) && ! is_int($token)) {
                continue;
            }

            if ($token === '') {
                continue;
            }

            return (string) $token;
        }

        return null;
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
