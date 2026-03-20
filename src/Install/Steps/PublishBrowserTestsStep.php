<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Publishes starter Pest browser test stubs (`pest-e2e-browser-tests`).
 */
final class PublishBrowserTestsStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return $ctx->plan->publishBrowserTests;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($ctx->publish(['pest-e2e-browser-tests'], $ctx->force) === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info('Pest E2E browser tests published successfully.');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->error('Failed to publish Pest E2E browser tests');
        }

        return StepResult::fail();
    }

    /**
     * {@inheritdoc}
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        if (! $ctx->isQuiet() && InstallProjectProbe::browserTestsExist()) {
            $ctx->info('Pest E2E browser tests already published.');
        }
    }
}
