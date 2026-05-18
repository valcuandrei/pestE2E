<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Commands;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallPlan;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\Steps\AddCsrfExclusionStep;
use ValcuAndrei\PestE2E\Install\Steps\ConfigurePhpunitStep;
use ValcuAndrei\PestE2E\Install\Steps\CreateEnvTestingStep;
use ValcuAndrei\PestE2E\Install\Steps\CreateTestingDatabaseStep;
use ValcuAndrei\PestE2E\Install\Steps\MergeSailWslgHeadedComposeStep;
use ValcuAndrei\PestE2E\Install\Steps\PlaywrightInstallStep;
use ValcuAndrei\PestE2E\Install\Steps\PublishBaseTestCaseStep;
use ValcuAndrei\PestE2E\Install\Steps\PublishBrowserTestsStep;
use ValcuAndrei\PestE2E\Install\Steps\PublishConfigStep;
use ValcuAndrei\PestE2E\Install\Steps\PublishJsHarnessStep;
use ValcuAndrei\PestE2E\Install\Steps\PublishPlaywrightTestsStep;
use ValcuAndrei\PestE2E\Install\Steps\RegisterPhpunitExtensionStep;
use ValcuAndrei\PestE2E\Install\Steps\SyncPhpunitBrowserTestsuiteStep;
use ValcuAndrei\PestE2E\Install\Steps\UpdatePestConfigStep;
use ValcuAndrei\PestE2E\Support\JsPackageManager;

use function Laravel\Prompts\confirm;

/**
 * Orchestrates `pest-e2e:install`: validates input, resolves {@see InstallPlan} (prompts + flags),
 * runs {@see InstallStep} instances in order, then prints completion output.
 */
final class InstallCommand extends Command
{
    protected $signature = 'pest-e2e:install'
        .' {--force : Overwrite existing files}'
        .' {--update-pest : Update the Pest config to include the E2ETestCase}'
        .' {--publish-config : Publish the Pest E2E config}'
        .' {--publish-base-test-case : Publish the Pest E2E base test case}'
        .' {--publish-js-harness : Publish the Pest E2E JS harness}'
        .' {--publish-js-playwright : Publish the Pest E2E JS Playwright}'
        .' {--publish-browser-tests : Publish the Pest E2E browser tests}'
        .' {--publish-playwright-tests : Publish the Pest E2E Playwright tests}'
        .' {--add-csrf-exclusion : Add pest-e2e auth route to CSRF exclusion (required for Herd/Windows)}'
        .' {--setup-env-testing : Create .env.testing with parallel-safe E2E overrides}'
        .' {--update-testing-env : Update an existing .env.testing with parallel-safe E2E overrides}'
        .' {--setup-testing-database : Create database/testing.sqlite for SQLite tests}'
        .' {--configure-phpunit : Comment out DB/cache env in phpunit.xml so .env.testing controls them}'
        .' {--sail-wslg-headed : Add WSLg display/volume config to the Sail laravel.test service in compose file}'
        .' {--install-playwright : Install Playwright}'
        .' {--package-manager= : Package manager written into E2ETestCase (npm, yarn, pnpm, bun); skips detection}'
        .' {--yes : Answer yes to all questions (shortcut for --update-pest --install-playwright --publish-config --publish-base-test-case --publish-js-harness --publish-js-playwright --add-csrf-exclusion --setup-env-testing --update-testing-env --setup-testing-database --configure-phpunit --sail-wslg-headed)}'
        .' {--no : Answer no to all questions (can be overridden by the individual options)}'
        .' {--unattended : Answer yes to all questions (shortcut for --yes)}';

    protected $description = 'Install Pest E2E assets (JS harness + E2ETestCase stub)';

    /**
     * @param  JsPackageManager  $jsPackageManager  Resolves JS package manager and runs npm/yarn/pnpm/bun commands.
     */
    public function __construct(
        private readonly JsPackageManager $jsPackageManager,
    ) {
        parent::__construct();
    }

    /**
     * Run the installer: build plan, execute steps, print success message.
     */
    public function handle(): int
    {
        if (! file_exists(base_path('tests/Pest.php'))) {
            $this->error('Pest config file not found. Please run "php artisan pest:init" to create it.');

            return self::FAILURE;
        }

        $packageManagerOption = $this->option('package-manager');
        if (is_string($packageManagerOption) && $packageManagerOption !== '') {
            $allowed = $this->jsPackageManager->packageManagerKeysInRegistrationOrder();
            if (! in_array($packageManagerOption, $allowed, true)) {
                $this->error(sprintf(
                    'Invalid --package-manager "%s". Use one of: %s.',
                    $packageManagerOption,
                    implode(', ', $allowed)
                ));

                return self::FAILURE;
            }
        }

        $force = (bool) $this->option('force');
        $quiet = $this->output->isQuiet();
        $hasPlaywrightInstalled = $this->hasPlaywrightInstalled();
        $installPlaywright = $hasPlaywrightInstalled ? false : $this->shouldInstallPlaywright();

        $plan = new InstallPlan(
            addCsrfExclusion: $this->shouldAddCsrfExclusion(),
            publishBaseTestCase: $this->shouldPublishBaseTestCase(),
            updatePestConfig: $this->shouldUpdatePestConfig(),
            publishConfig: $this->shouldPublishConfig(),
            installPlaywright: $installPlaywright,
            publishJsHarness: $this->shouldPublishJsHarness(),
            publishJsPlaywright: ($hasPlaywrightInstalled || $installPlaywright) && $this->shouldPublishJsPlaywright(),
            publishBrowserTests: $this->shouldPublishBrowserTests(),
            publishPlaywrightTests: $this->shouldPublishPlaywrightTests(),
            setupEnvTesting: $this->shouldSetupEnvTesting(),
            setupTestingDatabase: $this->shouldSetupTestingDatabase(),
            configurePhpunit: $this->shouldConfigurePhpunit(),
            mergeSailWslgHeaded: $this->shouldMergeSailWslgHeadedCompose(),
        );

        $ctx = new InstallContext(
            $plan,
            $this,
            $this->input,
            $this->output,
            $this->jsPackageManager,
            $force,
            $hasPlaywrightInstalled,
            fn (array $tags, bool $forcePublish): int => $this->publish($tags, $forcePublish),
            fn (string $name) => $this->option($name),
        );

        foreach ($this->installSteps() as $step) {
            if ($step->shouldRun($ctx)) {
                $result = $step->run($ctx);
                if (! $result->ok) {
                    return self::FAILURE;
                }
            } else {
                $step->afterSkipped($ctx);
            }
        }

        if (! $quiet) {
            $this->info('Pest E2E installed successfully');
            $this->info('There, now you have no excuses to not write E2E tests!');

            if (! $plan->publishJsHarness && ! InstallProjectProbe::jsHarnessExists()) {
                $this->info('When you are ready to publish the JS harness, run:');
                $this->info('  php artisan vendor:publish --tag=pest-e2e-js-harness');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Ordered pipeline of install steps (must match intended side-effect order).
     *
     * @return list<InstallStep>
     */
    private function installSteps(): array
    {
        return [
            new AddCsrfExclusionStep,
            new PublishBaseTestCaseStep,
            new UpdatePestConfigStep,
            new PublishConfigStep,
            new CreateEnvTestingStep,
            new CreateTestingDatabaseStep,
            new ConfigurePhpunitStep,
            new MergeSailWslgHeadedComposeStep,
            new RegisterPhpunitExtensionStep,
            new PublishBrowserTestsStep,
            new PublishPlaywrightTestsStep,
            new PublishJsHarnessStep,
            new PlaywrightInstallStep,
            new SyncPhpunitBrowserTestsuiteStep,
        ];
    }

    /**
     * @param  array<string>  $tags  Vendor publish tags (e.g. pest-e2e-config).
     */
    private function publish(array $tags, bool $force = false): int
    {
        return $this->call('vendor:publish', array_merge([
            '--tag' => $tags,
        ], $force ? ['--force' => true] : []));
    }

    /**
     * Whether `@playwright/test` is present in package.json / node_modules.
     */
    private function hasPlaywrightInstalled(): bool
    {
        $pkg = '@playwright/test';

        if ($this->jsPackageManager->hasJsAnyDependency($pkg)) {
            return true;
        }

        return $this->jsPackageManager->hasJsPackageInstalled($pkg);
    }

    /**
     * Resolve a yes/no for an install feature: explicit CLI flag, --yes/--no/--unattended, non-interactive default, or Laravel Prompts `confirm`.
     *
     * @param  string  $flag  Option name without leading dashes (e.g. `publish-config`).
     */
    private function shouldAccept(string $flag, string $message, bool $default = true): bool
    {
        if ($this->hasOptionFlag($flag)) {
            return true;
        }
        if ($this->hasOptionFlag('unattended') || $this->hasOptionFlag('yes')) {
            return true;
        }
        if ($this->hasOptionFlag('no')) {
            return false;
        }
        if (! $this->input->isInteractive()) {
            return false;
        }

        return confirm($message, $default);
    }

    /**
     * Whether to offer updating `tests/Pest.php` for E2ETestCase.
     */
    private function shouldUpdatePestConfig(): bool
    {
        return $this->shouldAccept('update-pest', 'This package requires updating the Pest config to include the E2ETestCase. Update it now?');
    }

    /**
     * Whether to publish `pest-e2e` config (skip prompt if already published unless flag set).
     */
    private function shouldPublishConfig(): bool
    {
        if (InstallProjectProbe::configExists() && ! $this->hasOptionFlag('publish-config')) {
            return false;
        }

        return $this->shouldAccept('publish-config', 'This package requires publishing the Pest E2E config. Publish it now?');
    }

    /**
     * Whether to publish the E2ETestCase stub.
     */
    private function shouldPublishBaseTestCase(): bool
    {
        if (InstallProjectProbe::e2eTestCaseExists() && ! $this->hasOptionFlag('publish-base-test-case')) {
            return false;
        }

        return $this->shouldAccept('publish-base-test-case', 'This package requires publishing the Pest E2E base test case. Publish it now?');
    }

    /**
     * Whether to publish the JS harness assets.
     */
    private function shouldPublishJsHarness(): bool
    {
        if (InstallProjectProbe::jsHarnessExists() && ! $this->hasOptionFlag('publish-js-harness')) {
            return false;
        }

        return $this->shouldAccept('publish-js-harness', 'This package requires publishing the Pest E2E JS harness. Publish it now?');
    }

    /**
     * Whether to publish the JS Playwright adapter.
     */
    private function shouldPublishJsPlaywright(): bool
    {
        if (InstallProjectProbe::jsPlaywrightExists() && ! $this->hasOptionFlag('publish-js-playwright')) {
            return false;
        }

        return $this->shouldAccept('publish-js-playwright', 'This package requires publishing the Pest E2E JS Playwright. Publish it now?');
    }

    /**
     * Whether to publish starter Browser suite stubs.
     */
    private function shouldPublishBrowserTests(): bool
    {
        if (InstallProjectProbe::browserTestsExist() && ! $this->hasOptionFlag('publish-browser-tests')) {
            return false;
        }

        return $this->shouldAccept('publish-browser-tests', 'Do you want to publish the Pest E2E browser tests?');
    }

    /**
     * Whether to publish starter Playwright JS tests.
     */
    private function shouldPublishPlaywrightTests(): bool
    {
        if (InstallProjectProbe::playwrightTestsExist() && ! $this->hasOptionFlag('publish-playwright-tests')) {
            return false;
        }

        return $this->shouldAccept('publish-playwright-tests', 'Do you want to publish the Pest E2E Playwright tests?');
    }

    /**
     * Whether to install `@playwright/test` and download browsers (when not already installed).
     */
    private function shouldInstallPlaywright(): bool
    {
        return $this->shouldAccept('install-playwright', 'This package requires installing Playwright. Install it now?');
    }

    /**
     * Whether to patch `bootstrap/app.php` for pest-e2e auth CSRF exclusion.
     */
    private function shouldAddCsrfExclusion(): bool
    {
        return $this->shouldAccept('add-csrf-exclusion', 'Add pest-e2e auth route to CSRF exclusion? (required for Herd/Windows)', true);
    }

    /**
     * Whether to create or update `.env.testing`.
     */
    private function shouldSetupEnvTesting(): bool
    {
        if (InstallProjectProbe::envTestingExists()) {
            if ($this->hasOptionFlag('setup-env-testing') || $this->hasOptionFlag('update-testing-env')) {
                return true;
            }

            return $this->shouldAccept('update-testing-env', 'Update .env.testing with parallel-safe E2E overrides?');
        }

        return $this->shouldAccept('setup-env-testing', 'Create .env.testing with parallel-safe E2E overrides?');
    }

    /**
     * Whether to create `database/testing.sqlite`.
     */
    private function shouldSetupTestingDatabase(): bool
    {
        if (InstallProjectProbe::testingDatabaseExists() && ! $this->hasOptionFlag('setup-testing-database')) {
            return false;
        }

        return $this->shouldAccept('setup-testing-database', 'Create database/testing.sqlite for SQLite tests?');
    }

    /**
     * Whether to comment DB/cache env vars in `phpunit.xml` for `.env.testing` control.
     */
    private function shouldConfigurePhpunit(): bool
    {
        if (InstallProjectProbe::phpunitIsConfiguredForEnvTesting() && ! $this->hasOptionFlag('configure-phpunit')) {
            return false;
        }

        return $this->shouldAccept('configure-phpunit', 'Comment out DB/cache env in phpunit.xml so .env.testing controls them?');
    }

    /**
     * Whether to merge WSLg display/volume settings into the Sail `laravel.test` compose service.
     */
    private function shouldMergeSailWslgHeadedCompose(): bool
    {
        if (! InstallProjectProbe::sailProjectDetected()) {
            return false;
        }

        if (InstallProjectProbe::composeFileHasSailWslgHeadedConfig()) {
            return false;
        }

        return $this->shouldAccept(
            'sail-wslg-headed',
            'Laravel Sail was detected. Add WSLg display and volume mounts to the laravel.test service for headed Playwright in Docker (WSL2 + WSLg)? See README § Headed Mode in Sail.',
            false
        );
    }

    /**
     * Whether the raw argv contains `--{name}` (for flags not exposed as Laravel options).
     */
    private function hasOptionFlag(string $name): bool
    {
        $needle = '--'.$name;

        return in_array($needle, (array) $_SERVER['argv'], true);
    }
}
