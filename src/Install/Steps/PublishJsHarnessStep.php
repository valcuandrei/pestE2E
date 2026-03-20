<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Publishes the Node-side Pest E2E harness assets (`pest-e2e-js-harness`).
 */
final class PublishJsHarnessStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return $ctx->plan->publishJsHarness;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($ctx->publish(['pest-e2e-js-harness'], $ctx->force) === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info('Pest E2E JS Harness published successfully.');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->error('Failed to publish Pest E2E JS Harness');
        }

        return StepResult::fail();
    }

    /**
     * {@inheritdoc}
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        if (! $ctx->isQuiet() && InstallProjectProbe::jsHarnessExists()) {
            $ctx->info('Pest E2E JS Harness already published.');
        }
    }
}
