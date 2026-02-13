<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Enums;

enum TestStatusType: string
{
    case PASSED = 'passed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';

    /**
     * Get the symbol for the test status.
     *
     * @example '<fg=green>✓</fg=green>' for PASSED
     * @example '<fg=red>✗</fg=red>' for FAILED
     * @example '<fg=yellow>-</fg=yellow>' for SKIPPED
     */
    public function getSymbol(): string
    {
        return match ($this) {
            self::PASSED => '<fg=green>✓</fg=green>',
            self::FAILED => '<fg=red>✗</fg=red>',
            self::SKIPPED => '<fg=yellow>-</fg=yellow>',
        };
    }
}
