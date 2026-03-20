<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Publishes JS Playwright example tests when Playwright is or will be available.
 */
final class PublishPlaywrightTestsStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return ($ctx->hasPlaywrightInstalled || $ctx->plan->installPlaywright)
            && $ctx->plan->publishPlaywrightTests;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($ctx->publish(['pest-e2e-playwright-tests'], $ctx->force) === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info('Pest E2E Playwright tests published successfully.');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->error('Failed to publish Pest E2E Playwright tests');
        }

        return StepResult::fail();
    }

    /**
     * {@inheritdoc}
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        if (! $ctx->isQuiet() && InstallProjectProbe::playwrightTestsExist()) {
            $ctx->info('Pest E2E Playwright tests already published.');
        }
    }
}
