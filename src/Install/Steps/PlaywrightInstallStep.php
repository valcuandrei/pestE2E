<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallPackageManagerResolver;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;
use ValcuAndrei\PestE2E\Support\CliOptions;

/**
 * Installs `@playwright/test`, runs `playwright install`, and optionally publishes the JS Playwright adapter.
 */
final class PlaywrightInstallStep extends InstallStep
{
    /**
     * Always runs: no-op branches when Playwright is already satisfied and publishing is skipped.
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        $publishJsPlaywright = $ctx->plan->publishJsPlaywright;
        $force = $ctx->force;

        $publishPlaywrightAdapter = function () use ($ctx, $publishJsPlaywright, $force): int {
            if ($publishJsPlaywright) {
                if ($ctx->publish(['pest-e2e-js-playwright'], $force) === Command::SUCCESS) {
                    if (! $ctx->isQuiet()) {
                        $ctx->info('Pest E2E JS Playwright published successfully.');
                    }
                } else {
                    if (! $ctx->isQuiet()) {
                        $ctx->error('Failed to publish Pest E2E JS Playwright');
                    }

                    return Command::FAILURE;
                }
            } elseif (! $ctx->isQuiet() && InstallProjectProbe::jsPlaywrightExists()) {
                $ctx->info('Pest E2E JS Playwright already published.');
            }

            return Command::SUCCESS;
        };

        if (! $ctx->hasPlaywrightInstalled) {
            if ($ctx->plan->installPlaywright) {
                if ($this->installPlaywrightPackage($ctx) === Command::SUCCESS) {
                    if (! $ctx->isQuiet()) {
                        $ctx->info('Playwright installed successfully.');
                    }

                    return $publishPlaywrightAdapter() === Command::FAILURE
                        ? StepResult::fail()
                        : StepResult::ok();
                }

                if (! $ctx->isQuiet()) {
                    $ctx->error('Failed to install Playwright or download browsers (playwright install).');
                }

                return StepResult::fail();
            }

            if (! $ctx->isQuiet()) {
                $pkg = $this->playwrightPackage();
                $ctx->warn($pkg.' is not installed. Install it to run E2E tests:');
                $ctx->warn('  npm i -D '.$pkg);
                $ctx->warn('  npx playwright install');
                $ctx->warn('  php artisan vendor:publish --tag=pest-e2e-js-playwright');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->info($this->playwrightPackage().' already installed.');
        }

        return $publishPlaywrightAdapter() === Command::FAILURE
            ? StepResult::fail()
            : StepResult::ok();
    }

    /**
     * npm package name for the Playwright test runner dependency.
     */
    private function playwrightPackage(): string
    {
        return '@playwright/test';
    }

    /**
     * Dev-install Playwright via the resolved JS package manager, then download browsers with `playwright install`.
     */
    private function installPlaywrightPackage(InstallContext $ctx): int
    {
        $previousCliPm = CliOptions::$packageManager;
        CliOptions::$packageManager = InstallPackageManagerResolver::e2ePackageManagerKey($ctx);

        try {
            $tty = Process::isTtySupported()
                && $ctx->isInteractive()
                && ! (bool) $ctx->option('unattended');

            $out = function (string $type, string $buffer) use ($ctx): void {
                $ctx->writeToOutput($buffer);
            };

            $process = $ctx->jsPackageManager->installJsPackage(
                package: $this->playwrightPackage(),
                dev: true,
                tty: $tty,
                outputCallback: $out,
            );

            if (! $process || ! $process->isSuccessful()) {
                return Command::FAILURE;
            }

            $browsers = $ctx->jsPackageManager->runLocalOrDlxBinary('playwright', ['install'], $tty, $out);
            if (! $browsers || ! $browsers->isSuccessful()) {
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } finally {
            CliOptions::$packageManager = $previousCliPm;
        }
    }
}
