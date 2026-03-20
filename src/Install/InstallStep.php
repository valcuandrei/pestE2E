<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

/**
 * One install step: gate with {@see shouldRun()}, execute with {@see run()}, optional {@see afterSkipped()} messaging.
 */
abstract class InstallStep
{
    /**
     * Whether this step should execute for the current plan and project state.
     */
    abstract public function shouldRun(InstallContext $ctx): bool;

    /**
     * Perform the step; return failure to abort the installer (non-zero exit).
     */
    abstract public function run(InstallContext $ctx): StepResult;

    /**
     * Called when {@see shouldRun()} is false; emit "already done" style messages if appropriate.
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        // optional informational branch when shouldRun is false
    }
}
