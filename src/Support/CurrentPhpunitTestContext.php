<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * Stores the current PHPUnit test identifier to associate E2E runs with their parent test.
 *
 * @internal
 */
final class CurrentPhpunitTestContext
{
    private static ?string $currentTestId = null;

    /**
     * Set the current test ID.
     */
    public function set(string $testId): void
    {
        self::$currentTestId = $testId;
    }

    /**
     * Get the current test ID.
     */
    public function get(): ?string
    {
        return self::$currentTestId;
    }

    /**
     * Clear the current test ID.
     */
    public function clear(): void
    {
        self::$currentTestId = null;
    }
}
