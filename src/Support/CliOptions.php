<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * @internal
 */
final class CliOptions
{
    public static bool $browse = false;

    public static bool $debug = false;

    /**
     * Set the CLI options from the given arguments.
     *
     * @param  array<int, string>  $arguments
     */
    public static function fromArguments(array $arguments): void
    {
        self::$debug = in_array('--debug', $arguments, true);
        self::$browse = in_array('--browse', $arguments, true) || in_array('--headed', $arguments, true) || self::$debug;
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
