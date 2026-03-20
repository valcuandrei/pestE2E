<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallPackageManagerResolver;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Publishes the E2ETestCase stub and injects the resolved JS package manager into the generated file.
 */
final class PublishBaseTestCaseStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return $ctx->plan->publishBaseTestCase;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        $result = $ctx->publish(['pest-e2e-test-case'], $ctx->force);

        if ($result === Command::SUCCESS) {
            InstallPackageManagerResolver::injectIntoE2ETestCase($ctx);
            if (! $ctx->isQuiet()) {
                $ctx->info('Pest E2E base test case published successfully.');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->error('Failed to publish Pest E2E base test case');
        }

        return StepResult::fail();
    }

    /**
     * {@inheritdoc}
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        if (! $ctx->isQuiet() && InstallProjectProbe::e2eTestCaseExists()) {
            $ctx->info('Pest E2E base test case already published.');
        }
    }
}
