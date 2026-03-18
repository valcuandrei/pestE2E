<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Support\JsPackageManager;

final class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pest-e2e:install'
        .' {--force : Overwrite existing files}'
        .' {--update-pest : Update the Pest config to include the E2ETestCase}'
        .' {--publish-config : Publish the Pest E2E config}'
        .' {--publish-base-test-case : Publish the Pest E2E base test case}'
        .' {--publish-js-harness : Publish the Pest E2E JS harness}'
        .' {--publish-js-playwright : Publish the Pest E2E JS Playwright}'
        .' {--add-csrf-exclusion : Add pest-e2e auth route to CSRF exclusion (required for Herd/Windows)}'
        .' {--install-playwright : Install Playwright}'
        .' {--yes : Answer yes to all questions (shortcut for --update-pest --install-playwright --publish-config --publish-base-test-case --publish-js-harness --publish-js-playwright --add-csrf-exclusion)}'
        .' {--no : Answer no to all questions (can be overridden by the individual options)}'
        .' {--unattended : Answer yes to all questions (shortcut for --yes)}';

    /**
     * The console command description.
     */
    protected $description = 'Install Pest E2E assets (JS harness + E2ETestCase stub)';

    private ?string $pestPhp = null;

    public function __construct(
        private readonly JsPackageManager $jsPackageManager,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->pestPhpExists()) {
            $this->error('Pest config file not found. Please run "php artisan pest:init" to create it.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $quiet = $this->output->isQuiet();
        $hasPlaywrightInstalled = $this->hasPlaywrightInstalled();
        // ask all questions at once
        $publishBaseTestCase = $this->shouldPublishBaseTestCase();
        $updatePestConfig = $this->shouldUpdatePestConfig();
        $publishConfig = $this->shouldPublishConfig();
        $installPlaywright = $hasPlaywrightInstalled ? false : $this->shouldInstallPlaywright();
        $publishJsHarness = $this->shouldPublishJsHarness();
        $publishJsPlaywright = ($hasPlaywrightInstalled || $installPlaywright) && $this->shouldPublishJsPlaywright();
        $addCsrfExclusion = $this->shouldAddCsrfExclusion();

        if ($addCsrfExclusion) {
            if ($this->addCsrfExclusion($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('CSRF exclusion for pest-e2e auth route added successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->warn('Could not add CSRF exclusion. Add manually: $middleware->validateCsrfTokens(except: [\'/pest-e2e/auth/login\']);');
                }
            }
        }

        if ($publishBaseTestCase) {
            if ($this->publishBaseTestCase($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('Pest E2E base test case published successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to publish Pest E2E base test case');
                }

                return self::FAILURE;
            }
        }

        if (! $this->pestPhpHasE2ETestCase() && $updatePestConfig) {
            if ($this->updatePestConfig() === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('Pest config updated successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to update pest config');
                }

                return self::FAILURE;
            }
        } elseif (! $quiet && $this->pestPhpHasE2ETestCase()) {
            $this->info('Pest config already includes E2ETestCase.');
        }

        if ($publishConfig) {
            if ($this->publishConfig($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('Pest E2E config published successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to publish Pest E2E config');
                }

                return self::FAILURE;
            }
        }

        $publishPlaywrightAdapter = function () use ($force, $quiet, $publishJsPlaywright): int {
            if ($publishJsPlaywright) {
                if ($this->publishJsPlaywright($force) === self::SUCCESS) {
                    if (! $quiet) {
                        $this->info('Pest E2E JS Playwright published successfully.');
                    }
                } else {
                    if (! $quiet) {
                        $this->error('Failed to publish Pest E2E JS Playwright');
                    }

                    return self::FAILURE;
                }
            }

            return self::SUCCESS;
        }; // we only publish the JS playwright adapter if playwright is installed

        if ($publishJsHarness) {
            if ($this->publishJsHarness($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('Pest E2E JS Harness published successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to publish Pest E2E JS Harness');
                }

                return self::FAILURE;
            }
        }

        if (! $hasPlaywrightInstalled) {
            if ($installPlaywright) {
                if ($this->installPlaywright() === self::SUCCESS) {
                    if (! $quiet) {
                        $this->info('Playwright installed successfully.');
                    }

                    if ($publishPlaywrightAdapter() === self::FAILURE) {
                        return self::FAILURE;
                    }
                } else {
                    if (! $quiet) {
                        $this->error('Failed to install Playwright');
                    }

                    return self::FAILURE;
                }
            } elseif (! $quiet) {
                $this->warn($this->playwrightPackage().' is not installed. Install it to run E2E tests:');
                $this->warn('  npm i -D '.$this->playwrightPackage());
                $this->warn('  npx playwright install');
                $this->warn('  php artisan vendor:publish --tag=pest-e2e-js-playwright');
            }
        } else {
            if (! $quiet) {
                $this->info($this->playwrightPackage().' already installed.');
            }
            if ($publishPlaywrightAdapter() === self::FAILURE) {
                return self::FAILURE;
            }
        }

        if (! $quiet) {
            $this->info('Pest E2E installed successfully');
            $this->info('There, now you have no excuses to not write E2E tests!');

            if (! $publishJsHarness) {
                $this->info('When you are ready to publish the JS harness, run:');
                $this->info('  php artisan vendor:publish --tag=pest-e2e-js-harness');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Update the Pest config to include the E2ETestCase.
     */
    private function updatePestConfig(): int
    {
        $pest = $this->getPestPhp();

        if (! is_string($pest)) {
            return self::FAILURE;
        }

        $dbTrait = str_contains($pest, 'RefreshDatabase')
            ? 'RefreshDatabase'
            : (str_contains($pest, 'DatabaseTransactions')
                ? 'DatabaseTransactions'
                : 'DatabaseMigrations');
        $dbTraitNamespace = 'Illuminate\Foundation\Testing\\'.$dbTrait;

        if (! str_contains($pest, 'use '.$dbTraitNamespace.';')) {
            if (preg_match('/^<\?php\s+declare\(strict_types=1\);\s*/', $pest) === 1) {
                $pest = preg_replace(
                    '/^<\?php\s+declare\(strict_types=1\);\s*/',
                    "<?php\n\ndeclare(strict_types=1);\n\nuse {$dbTraitNamespace};\n\n",
                    $pest,
                    1
                ) ?? $pest;
            } else {
                $pest = preg_replace(
                    '/^<\?php\s*/',
                    "<?php\n\nuse {$dbTraitNamespace};\n\n",
                    $pest,
                    1
                ) ?? $pest;
            }
        }

        $pest = preg_replace('/\?>\s*$/', '', $pest) ?? $pest;

        $pest .= "\n\npest()->extend(Tests\\E2ETestCase::class)\n"
            .'    ->use('.$dbTrait."::class)\n"
            ."    ->in('Browser');\n";

        if (file_put_contents($this->pestPhpPath(), $pest) === false) {
            return self::FAILURE;
        }

        $this->pestPhp = $pest;

        return self::SUCCESS;
    }

    /**
     * Publish the Pest E2E JS assets.
     *
     * @param  array<string>  $tags
     */
    private function publish(array $tags, bool $force = false): int
    {
        return $this->call('vendor:publish', array_merge([
            '--tag' => $tags,
        ], $force ? ['--force' => true] : []));
    }

    /**
     * Publish the Pest E2E config.
     */
    private function publishConfig(bool $force = false): int
    {
        return $this->publish(['pest-e2e-config'], $force);
    }

    /**
     * Publish the Pest E2E base test case.
     */
    private function publishBaseTestCase(bool $force = false): int
    {
        return $this->publish(['pest-e2e-test-case'], $force);
    }

    /**
     * Publish the Pest E2E JS harness.
     */
    private function publishJsHarness(bool $force = false): int
    {
        return $this->publish(['pest-e2e-js-harness'], $force);
    }

    /**
     * Publish the Pest E2E JS Playwright.
     */
    private function publishJsPlaywright(bool $force = false): int
    {
        return $this->publish(['pest-e2e-js-playwright'], $force);
    }

    /**
     * Check if Playwright is installed.
     */
    private function hasPlaywrightInstalled(): bool
    {
        if ($this->jsPackageManager->hasJsAnyDependency($this->playwrightPackage())) {
            return true;
        }

        return $this->jsPackageManager->hasJsPackageInstalled($this->playwrightPackage());
    }

    /**
     * Get the Playwright package name.
     */
    private function playwrightPackage(): string
    {
        return '@playwright/test';
    }

    /**
     * Install Playwright.
     */
    private function installPlaywright(): int
    {
        $tty = Process::isTtySupported() && $this->input->isInteractive() && ! (bool) $this->option('unattended');

        $process = $this->jsPackageManager->installJsPackage(
            package: $this->playwrightPackage(),
            dev: true,
            tty: $tty,
            outputCallback: function (string $type, string $buffer): void {
                $this->output->write($buffer);
            },
        );

        return $process && $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Get the Pest PHP path.
     */
    private function pestPhpPath(): string
    {
        return base_path('tests/Pest.php');
    }

    /**
     * Check if the Pest PHP file exists.
     */
    private function pestPhpExists(): bool
    {
        return file_exists($this->pestPhpPath());
    }

    /**
     * Get the Pest PHP file content.
     */
    private function getPestPhp(): ?string
    {
        if ($this->pestPhp !== null) {
            return $this->pestPhp;
        }

        $path = $this->pestPhpPath();
        $content = $this->pestPhpExists() ? file_get_contents($path) : false;
        $this->pestPhp = $content !== false ? $content : null;

        return $this->pestPhp;
    }

    /**
     * Check if the Pest PHP file has the E2ETestCase.
     */
    private function pestPhpHasE2ETestCase(): bool
    {
        $pest = $this->getPestPhp();

        if (! is_string($pest)) {
            return false;
        }

        return str_contains($pest, 'E2ETestCase::class');
    }

    /**
     * Should accept the flag.
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

        return $this->confirm($message, $default);
    }

    /**
     * Should update the Pest config.
     */
    private function shouldUpdatePestConfig(): bool
    {
        return $this->shouldAccept('update-pest', 'This package requires updating the Pest config to include the E2ETestCase. Update it now?');
    }

    /**
     * Should publish the Pest E2E config.
     */
    private function shouldPublishConfig(): bool
    {
        return $this->shouldAccept('publish-config', 'This package requires publishing the Pest E2E config. Publish it now?');
    }

    /**
     * Should publish the Pest E2E base test case.
     */
    private function shouldPublishBaseTestCase(): bool
    {
        return $this->shouldAccept('publish-base-test-case', 'This package requires publishing the Pest E2E base test case. Publish it now?');
    }

    /**
     * Should publish the Pest E2E JS harness.
     */
    private function shouldPublishJsHarness(): bool
    {
        return $this->shouldAccept('publish-js-harness', 'This package requires publishing the Pest E2E JS harness. Publish it now?');
    }

    /**
     * Should publish the Pest E2E JS Playwright.
     */
    private function shouldPublishJsPlaywright(): bool
    {
        return $this->shouldAccept('publish-js-playwright', 'This package requires publishing the Pest E2E JS Playwright. Publish it now?');
    }

    /**
     * Should install Playwright.
     */
    private function shouldInstallPlaywright(): bool
    {
        return $this->shouldAccept('install-playwright', 'This package requires installing Playwright. Install it now?');
    }

    /**
     * Should add CSRF exclusion for pest-e2e auth route.
     */
    private function shouldAddCsrfExclusion(): bool
    {
        return $this->shouldAccept('add-csrf-exclusion', 'Add pest-e2e auth route to CSRF exclusion? (required for Herd/Windows)', true);
    }

    /**
     * Add the pest-e2e auth route to CSRF exclusion in bootstrap/app.php.
     */
    private function addCsrfExclusion(bool $force = false): int
    {
        $path = base_path('bootstrap/app.php');
        if (! is_file($path)) {
            return self::FAILURE;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return self::FAILURE;
        }

        $exclusion = "validateCsrfTokens(except: ['/pest-e2e/auth/login'])";
        if (str_contains($content, $exclusion) || str_contains($content, 'pest-e2e/auth/login')) {
            return self::SUCCESS;
        }

        $newContent = preg_replace(
            '/(\$middleware->encryptCookies\([^)]+\);)/',
            '$1'."\n        \$middleware->".$exclusion.';',
            $content,
            1
        );

        if ($newContent === null || $newContent === $content) {
            return self::FAILURE;
        }

        return file_put_contents($path, $newContent) !== false ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Has option flag.
     */
    private function hasOptionFlag(string $name): bool
    {
        $needle = '--'.$name;

        return in_array($needle, (array) $_SERVER['argv'], true);
    }
}
