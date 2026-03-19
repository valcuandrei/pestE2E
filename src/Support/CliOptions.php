<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * @internal
 */
final class CliOptions
{
    private const VALID_PACKAGE_MANAGERS = ['npm', 'yarn', 'pnpm', 'bun'];

    public static bool $browse = false;

    public static bool $debug = false;

    public static ?string $packageManager = null;

    /**
     * Set the CLI options from the given arguments.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function fromArguments(array $arguments): void
    {
        self::$debug = in_array('--debug', $arguments, true);
        self::$browse = in_array('--browse', $arguments, true) || in_array('--headed', $arguments, true) || self::$debug;
        self::$packageManager = self::parseRunUsing($arguments);
    }

    /**
     * Parse --run-using= value from arguments.
     *
     * @param  array<int, mixed>  $arguments
     */
    private static function parseRunUsing(array $arguments): ?string
    {
        foreach ($arguments as $arg) {
            if (is_string($arg) && str_starts_with($arg, '--run-using=')) {
                $value = trim(substr($arg, strlen('--run-using=')));
                if ($value !== '' && in_array($value, self::VALID_PACKAGE_MANAGERS, true)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Filter pest-e2e CLI options from arguments.
     *
     * @param  array<int, mixed>  $arguments
     * @return array<int, string>
     */
    public static function filterArguments(array $arguments): array
    {
        $exactKeys = ['--browse', '--headed', '--debug'];

        $filtered = array_filter($arguments, function (mixed $arg) use ($exactKeys): bool {
            if (! is_string($arg)) {
                return true;
            }
            if (in_array($arg, $exactKeys, true)) {
                return false;
            }

            return ! str_starts_with($arg, '--run-using=');
        });

        return array_values(array_filter($filtered, is_string(...)));
    }

    /**
     * Get the option keys.
     *
     * @return array<int, string>
     */
    public static function optionKeys(): array
    {
        return ['--browse', '--headed', '--debug'];
    }
}
