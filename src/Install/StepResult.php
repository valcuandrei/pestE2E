<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

/**
 * Outcome of a single {@see InstallStep::run()}: success continues the pipeline, failure stops the command.
 */
final readonly class StepResult
{
    /**
     * @param  bool  $ok  Whether the install step completed successfully.
     */
    private function __construct(public bool $ok) {}

    /**
     * Step completed; continue or show success messaging.
     */
    public static function ok(): self
    {
        return new self(true);
    }

    /**
     * Step failed; installer should exit with failure.
     */
    public static function fail(): self
    {
        return new self(false);
    }
}
