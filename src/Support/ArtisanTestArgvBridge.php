<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * Strips pest-e2e flags from artisan test argv before ParaTest validates options.
 *
 * Maps flags to environment variables so Pest / parallel workers can read them.
 *
 * @internal
 */
final class ArtisanTestArgvBridge
{
    /**
     * @param  array<int, mixed>|null  $argv
     */
    public static function apply(?array &$argv = null): void
    {
        $usingGlobalArgv = $argv === null;

        if ($usingGlobalArgv) {
            $rawArgv = $_SERVER['argv'] ?? null;

            if (! is_array($rawArgv)) {
                return;
            }

            /** @var array<int, mixed> $argv */
            $argv = array_values($rawArgv);
        }

        if (! self::isArtisanTestCommand($argv)) {
            return;
        }

        $filtered = [];
        $changed = false;

        foreach ($argv as $argument) {
            if (! is_string($argument)) {
                $filtered[] = $argument;

                continue;
            }

            if (self::consumeArgument($argument)) {
                $changed = true;

                continue;
            }

            $filtered[] = $argument;
        }

        if (! $changed) {
            return;
        }

        $argv = $filtered;

        if ($usingGlobalArgv) {
            $_SERVER['argv'] = $argv;
        }
    }

    /**
     * @param  array<int, mixed>  $argv
     */
    private static function isArtisanTestCommand(array $argv): bool
    {
        $command = $argv[1] ?? null;

        return is_string($command) && $command === 'test';
    }

    private static function consumeArgument(string $argument): bool
    {
        return match (true) {
            $argument === '--pest-e2e-agent-output', $argument === '--pest-e2e-json' => self::setEnv('PEST_E2E_AGENT_OUTPUT', '1'),
            $argument === '--browse', $argument === '--headed' => self::setEnv('PEST_E2E_BROWSE', '1'),
            $argument === '--debug' => self::setEnv('PEST_E2E_DEBUG', '1'),
            str_starts_with($argument, '--run-using=') => self::consumeRunUsing($argument),
            default => false,
        };
    }

    private static function consumeRunUsing(string $argument): bool
    {
        $value = trim(substr($argument, strlen('--run-using=')));

        if ($value === '' || ! in_array($value, ['npm', 'yarn', 'pnpm', 'bun'], true)) {
            return false;
        }

        self::setEnv('PEST_E2E_PACKAGE_MANAGER', $value);

        return true;
    }

    private static function setEnv(string $key, string $value): true
    {
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");

        return true;
    }
}
