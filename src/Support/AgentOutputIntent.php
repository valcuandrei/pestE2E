<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * Persists agent-output environment variables for Pest subprocesses spawned by artisan test.
 *
 * @internal
 */
final class AgentOutputIntent
{
    private const FILENAME = '.agent-intent.json';

    public static function persistFromEnvironment(): void
    {
        $variables = AgentParallelMode::forwardableEnvironmentVariables();

        if ($variables === []) {
            self::clear();

            return;
        }

        $directory = self::directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }

        file_put_contents(
            $directory.'/'.self::FILENAME,
            json_encode($variables, JSON_THROW_ON_ERROR),
        );
    }

    public static function hydrateEnvironment(): void
    {
        $path = self::path();

        if (! is_file($path)) {
            return;
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return;
        }

        $variables = json_decode($contents, true);

        if (! is_array($variables)) {
            return;
        }

        foreach ($variables as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    public static function clear(): void
    {
        $path = self::path();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function path(): string
    {
        return self::directory().'/'.self::FILENAME;
    }

    private static function directory(): string
    {
        if (function_exists('storage_path')) {
            try {
                return storage_path('framework/testing/pest-e2e-agent-output');
            } catch (\Throwable) {
                // storage_path may be unavailable outside a booted app
            }
        }

        $cwd = getcwd();

        if ($cwd !== false && is_dir($cwd.'/storage')) {
            return $cwd.'/storage/framework/testing/pest-e2e-agent-output';
        }

        return rtrim(sys_get_temp_dir(), '/').'/pest-e2e-agent-output';
    }
}
