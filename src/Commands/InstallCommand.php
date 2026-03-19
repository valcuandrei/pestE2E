<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\PHPUnit\PestE2EPhpunitExtension;
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
        .' {--publish-browser-tests : Publish the Pest E2E browser tests}'
        .' {--publish-playwright-tests : Publish the Pest E2E Playwright tests}'
        .' {--add-csrf-exclusion : Add pest-e2e auth route to CSRF exclusion (required for Herd/Windows)}'
        .' {--setup-env-testing : Create .env.testing from .env with E2E-appropriate overrides}'
        .' {--setup-testing-database : Create database/testing.sqlite for SQLite tests}'
        .' {--configure-phpunit : Comment out DB/cache env in phpunit.xml so .env.testing controls them}'
        .' {--install-playwright : Install Playwright}'
        .' {--package-manager= : Package manager written into E2ETestCase (npm, yarn, pnpm, bun); skips detection}'
        .' {--yes : Answer yes to all questions (shortcut for --update-pest --install-playwright --publish-config --publish-base-test-case --publish-js-harness --publish-js-playwright --add-csrf-exclusion --setup-env-testing --setup-testing-database --configure-phpunit)}'
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
        // ask all questions at once
        $publishBaseTestCase = $this->shouldPublishBaseTestCase();
        $updatePestConfig = $this->shouldUpdatePestConfig();
        $publishConfig = $this->shouldPublishConfig();
        $installPlaywright = $hasPlaywrightInstalled ? false : $this->shouldInstallPlaywright();
        $publishJsHarness = $this->shouldPublishJsHarness();
        $publishJsPlaywright = ($hasPlaywrightInstalled || $installPlaywright) && $this->shouldPublishJsPlaywright();
        $publishBrowserTests = $this->shouldPublishBrowserTests();
        $publishPlaywrightTests = $this->shouldPublishPlaywrightTests();
        $addCsrfExclusion = $this->shouldAddCsrfExclusion();
        $setupEnvTesting = $this->shouldSetupEnvTesting();
        $setupTestingDatabase = $this->shouldSetupTestingDatabase();
        $configurePhpunit = $this->shouldConfigurePhpunit();

        if ($addCsrfExclusion) {
            if ($this->addCsrfExclusion() === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('CSRF exclusion for pest-e2e auth route added successfully.');
                }
            } elseif (! $quiet) {
                $this->warn('Could not add CSRF exclusion. Add manually: $middleware->validateCsrfTokens(except: [\'/pest-e2e/auth/login\']);');
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
        } elseif (! $quiet && $this->e2eTestCaseExists()) {
            $this->info('Pest E2E base test case already published.');
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
        } elseif (! $quiet && $this->configExists()) {
            $this->info('Pest E2E config already published.');
        }

        if ($setupEnvTesting) {
            if ($this->createEnvTesting($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('.env.testing created successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to create .env.testing');
                }

                return self::FAILURE;
            }
        } elseif (! $quiet && $this->envTestingExists()) {
            $this->info('.env.testing already exists.');
        }

        if ($setupTestingDatabase) {
            if ($this->createTestingDatabase($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('database/testing.sqlite created successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to create database/testing.sqlite');
                }

                return self::FAILURE;
            }
        } elseif (! $quiet && $this->testingDatabaseExists()) {
            $this->info('database/testing.sqlite already exists.');
        }

        if ($configurePhpunit) {
            if ($this->configurePhpunit($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('phpunit.xml configured for .env.testing.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to configure phpunit.xml');
                }

                return self::FAILURE;
            }
        } elseif (! $quiet && $this->phpunitIsConfiguredForEnvTesting()) {
            $this->info('phpunit.xml already configured for .env.testing.');
        }

        if ($this->phpunitExtensionIsRegistered()) {
            if (! $quiet) {
                $this->info('Pest E2E PHPUnit extension already registered.');
            }
        } elseif ($this->registerPhpunitExtension() === self::SUCCESS && ! $quiet) {
            $this->info('Pest E2E PHPUnit extension registered.');
        }

        if ($publishBrowserTests) {
            if ($this->publishBrowserTests($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('Pest E2E browser tests published successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to publish Pest E2E browser tests');
                }

                return self::FAILURE;
            }
        } elseif (! $quiet && $this->browserTestsExist()) {
            $this->info('Pest E2E browser tests already published.');
        }

        if (($hasPlaywrightInstalled || $installPlaywright) && $publishPlaywrightTests) {
            if ($this->publishPlaywrightTests($force) === self::SUCCESS) {
                if (! $quiet) {
                    $this->info('Pest E2E Playwright tests published successfully.');
                }
            } else {
                if (! $quiet) {
                    $this->error('Failed to publish Pest E2E Playwright tests');
                }

                return self::FAILURE;
            }
        } elseif (! $quiet && $this->playwrightTestsExist()) {
            $this->info('Pest E2E Playwright tests already published.');
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
            } elseif (! $quiet && $this->jsPlaywrightExists()) {
                $this->info('Pest E2E JS Playwright already published.');
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
        } elseif (! $quiet && $this->jsHarnessExists()) {
            $this->info('Pest E2E JS Harness already published.');
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

            if (! $publishJsHarness && ! $this->jsHarnessExists()) {
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

        $dbTrait = 'DatabaseMigrations';
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
        $result = $this->publish(['pest-e2e-test-case'], $force);

        if ($result === self::SUCCESS) {
            $this->injectPackageManagerIntoE2ETestCase();
        }

        return $result;
    }

    /**
     * Inject the detected package manager into the published E2ETestCase.
     */
    private function injectPackageManagerIntoE2ETestCase(): void
    {
        $path = base_path('tests/E2ETestCase.php');
        if (! is_file($path)) {
            return;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return;
        }

        $detected = $this->resolvePackageManagerKeyForE2EStub();
        $content = str_replace('{{PACKAGE_MANAGER}}', $detected, $content);

        file_put_contents($path, $content);
    }

    /**
     * Resolve package manager for E2ETestCase stub: prefer installed binaries
     * ({@see JsPackageManager::getAvailablePackageManagers()}), prompt when
     * several exist, else fall back to lockfile-only detection.
     */
    private function resolvePackageManagerKeyForE2EStub(): string
    {
        $forced = $this->option('package-manager');
        if (is_string($forced) && $forced !== '') {
            return $forced;
        }

        $available = $this->jsPackageManager->getAvailablePackageManagers();

        if ($available === []) {
            return $this->jsPackageManager->defaultPackageManagerKeyFromLockfiles();
        }

        $ordered = $this->orderPackageManagerKeysByRegistration($available);

        if (count($ordered) === 1) {
            return $ordered[0];
        }

        $lockfileChoice = $this->jsPackageManager->defaultPackageManagerKeyFromLockfiles();

        if ($this->input->isInteractive()) {
            $default = in_array($lockfileChoice, $ordered, true) ? $lockfileChoice : $ordered[0];

            /** @var string */
            return $this->choice(
                'Which package manager should Pest E2E use for JS / Playwright commands?',
                $ordered,
                $default
            );
        }

        if (in_array($lockfileChoice, $ordered, true)) {
            return $lockfileChoice;
        }

        return $ordered[0];
    }

    /**
     * @param  array<string>  $keys
     * @return list<string>
     */
    private function orderPackageManagerKeysByRegistration(array $keys): array
    {
        $want = array_flip($keys);
        $ordered = [];
        foreach ($this->jsPackageManager->packageManagerKeysInRegistrationOrder() as $key) {
            if (isset($want[$key])) {
                $ordered[] = $key;
            }
        }

        foreach ($keys as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        return $ordered;
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
     * Publish the Pest E2E browser tests.
     */
    private function publishBrowserTests(bool $force = false): int
    {
        return $this->publish(['pest-e2e-browser-tests'], $force);
    }

    /**
     * Publish the Pest E2E Playwright tests.
     */
    private function publishPlaywrightTests(bool $force = false): int
    {
        return $this->publish(['pest-e2e-playwright-tests'], $force);
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
     * Check if the E2ETestCase.php has been published.
     */
    private function e2eTestCaseExists(): bool
    {
        return is_file(base_path('tests/E2ETestCase.php'));
    }

    /**
     * Check if the pest-e2e config has been published.
     */
    private function configExists(): bool
    {
        return is_file(config_path('pest-e2e.php'));
    }

    /**
     * Check if the JS harness has been published.
     */
    private function jsHarnessExists(): bool
    {
        return is_file(resource_path('js/pest-e2e/core.mjs'));
    }

    /**
     * Check if the JS Playwright adapter has been published.
     */
    private function jsPlaywrightExists(): bool
    {
        return is_file(resource_path('js/pest-e2e/playwright.mjs'));
    }

    /**
     * Check if the browser tests have been published.
     */
    private function browserTestsExist(): bool
    {
        return is_dir(base_path('tests/Browser'));
    }

    /**
     * Check if the Playwright tests have been published.
     */
    private function playwrightTestsExist(): bool
    {
        return is_dir(resource_path('js/e2e'));
    }

    /**
     * Check if .env.testing exists.
     */
    private function envTestingExists(): bool
    {
        return is_file(base_path('.env.testing'));
    }

    /**
     * Check if database/testing.sqlite exists.
     */
    private function testingDatabaseExists(): bool
    {
        return is_file(base_path('database/testing.sqlite'));
    }

    /**
     * Load phpunit.xml as DOMDocument.
     */
    private function loadPhpunitXml(string $path): ?\DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (@$dom->load($path) === false) {
            return null;
        }

        return $dom;
    }

    /**
     * Save DOMDocument to phpunit.xml.
     */
    private function savePhpunitXml(\DOMDocument $dom, string $path): bool
    {
        return $dom->save($path) !== false;
    }

    /**
     * Check if phpunit.xml has DB/cache env vars commented out (so .env.testing controls them).
     */
    private function phpunitIsConfiguredForEnvTesting(): bool
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return false;
        }

        $dom = $this->loadPhpunitXml($path);
        if (! $dom instanceof \DOMDocument) {
            return false;
        }

        $xpath = new \DOMXPath($dom);
        $varsToCheck = ['DB_CONNECTION', 'DB_DATABASE', 'CACHE_STORE', 'SESSION_DRIVER'];

        foreach ($varsToCheck as $var) {
            $nodes = $xpath->query("//php/env[@name='{$var}']");
            if ($nodes !== false && $nodes->length > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the Pest E2E PHPUnit extension is registered in phpunit.xml.
     */
    private function phpunitExtensionIsRegistered(): bool
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return false;
        }

        $dom = $this->loadPhpunitXml($path);
        if (! $dom instanceof \DOMDocument) {
            return false;
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//extensions/bootstrap[@class='ValcuAndrei\\PestE2E\\PHPUnit\\PestE2EPhpunitExtension']");

        return $nodes !== false && $nodes->length > 0;
    }

    /**
     * Register the Pest E2E PHPUnit extension in phpunit.xml.
     */
    private function registerPhpunitExtension(): int
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return self::SUCCESS;
        }

        if ($this->phpunitExtensionIsRegistered()) {
            return self::SUCCESS;
        }

        $dom = $this->loadPhpunitXml($path);
        if (! $dom instanceof \DOMDocument) {
            return self::FAILURE;
        }

        $root = $dom->documentElement;
        if (! $root instanceof \DOMElement) {
            return self::FAILURE;
        }

        $extensions = $dom->getElementsByTagName('extensions')->item(0);

        if ($extensions !== null) {
            $bootstrap = $dom->createElement('bootstrap');
            $bootstrap->setAttribute('class', PestE2EPhpunitExtension::class);
            $extensions->appendChild($bootstrap);
        } else {
            $extensions = $dom->createElement('extensions');
            $bootstrap = $dom->createElement('bootstrap');
            $bootstrap->setAttribute('class', PestE2EPhpunitExtension::class);
            $extensions->appendChild($bootstrap);
            $root->appendChild($extensions);
        }

        return $this->savePhpunitXml($dom, $path) ? self::SUCCESS : self::FAILURE;
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
        if ($this->configExists() && ! $this->hasOptionFlag('publish-config')) {
            return false;
        }

        return $this->shouldAccept('publish-config', 'This package requires publishing the Pest E2E config. Publish it now?');
    }

    /**
     * Should publish the Pest E2E base test case.
     */
    private function shouldPublishBaseTestCase(): bool
    {
        if ($this->e2eTestCaseExists() && ! $this->hasOptionFlag('publish-base-test-case')) {
            return false;
        }

        return $this->shouldAccept('publish-base-test-case', 'This package requires publishing the Pest E2E base test case. Publish it now?');
    }

    /**
     * Should publish the Pest E2E JS harness.
     */
    private function shouldPublishJsHarness(): bool
    {
        if ($this->jsHarnessExists() && ! $this->hasOptionFlag('publish-js-harness')) {
            return false;
        }

        return $this->shouldAccept('publish-js-harness', 'This package requires publishing the Pest E2E JS harness. Publish it now?');
    }

    /**
     * Should publish the Pest E2E JS Playwright.
     */
    private function shouldPublishJsPlaywright(): bool
    {
        if ($this->jsPlaywrightExists() && ! $this->hasOptionFlag('publish-js-playwright')) {
            return false;
        }

        return $this->shouldAccept('publish-js-playwright', 'This package requires publishing the Pest E2E JS Playwright. Publish it now?');
    }

    /**
     * Should publish the Pest E2E browser tests.
     */
    private function shouldPublishBrowserTests(): bool
    {
        if ($this->browserTestsExist() && ! $this->hasOptionFlag('publish-browser-tests')) {
            return false;
        }

        return $this->shouldAccept('publish-browser-tests', 'Do you want to publish the Pest E2E browser tests?');
    }

    /**
     * Should publish the Pest E2E Playwright tests.
     */
    private function shouldPublishPlaywrightTests(): bool
    {
        if ($this->playwrightTestsExist() && ! $this->hasOptionFlag('publish-playwright-tests')) {
            return false;
        }

        return $this->shouldAccept('publish-playwright-tests', 'Do you want to publish the Pest E2E Playwright tests?');
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
     * Should create .env.testing.
     */
    private function shouldSetupEnvTesting(): bool
    {
        if ($this->envTestingExists() && ! $this->hasOptionFlag('setup-env-testing')) {
            return false;
        }

        return $this->shouldAccept('setup-env-testing', 'Create .env.testing from .env with E2E-appropriate overrides?');
    }

    /**
     * Should create database/testing.sqlite.
     */
    private function shouldSetupTestingDatabase(): bool
    {
        if ($this->testingDatabaseExists() && ! $this->hasOptionFlag('setup-testing-database')) {
            return false;
        }

        return $this->shouldAccept('setup-testing-database', 'Create database/testing.sqlite for SQLite tests?');
    }

    /**
     * Should configure phpunit.xml to let .env.testing control DB/cache.
     */
    private function shouldConfigurePhpunit(): bool
    {
        if ($this->phpunitIsConfiguredForEnvTesting() && ! $this->hasOptionFlag('configure-phpunit')) {
            return false;
        }

        return $this->shouldAccept('configure-phpunit', 'Comment out DB/cache env in phpunit.xml so .env.testing controls them?');
    }

    /**
     * Create .env.testing from .env with E2E-appropriate overrides.
     */
    private function createEnvTesting(bool $force = false): int
    {
        $path = base_path('.env.testing');
        if (is_file($path) && ! $force) {
            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            if (! $this->output->isQuiet()) {
                $this->warn('.env file not found. Skipping .env.testing creation. Run php artisan key:generate first.');
            }

            return self::SUCCESS;
        }

        $content = file_get_contents($envPath);
        if ($content === false) {
            return self::FAILURE;
        }

        $overrides = [
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://127.0.0.1',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => 'testing',
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'database',
            'PEST_E2E_AUTH_ROUTE_ENABLED' => 'true',
        ];

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return self::FAILURE;
        }

        $result = [];
        $seen = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $result[] = $line;

                continue;
            }

            if (preg_match('/^([A-Za-z_]\w*)=(.*)$/', $trimmed, $m) === 1) {
                $key = $m[1];
                $seen[$key] = true;
                if (array_key_exists($key, $overrides)) {
                    $result[] = $key.'='.$overrides[$key];

                    continue;
                }
            }

            $result[] = $line;
        }

        foreach ($overrides as $key => $value) {
            if (! isset($seen[$key])) {
                $result[] = $key.'='.$value;
            }
        }

        $output = implode("\n", $result);

        return file_put_contents($path, $output) !== false ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Create database/testing.sqlite for SQLite tests.
     */
    private function createTestingDatabase(bool $force = false): int
    {
        $path = base_path('database/testing.sqlite');
        if (is_file($path) && ! $force) {
            return self::SUCCESS;
        }

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return self::FAILURE;
        }

        return touch($path) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Comment out DB/cache env vars in phpunit.xml so .env.testing controls them.
     */
    private function configurePhpunit(bool $force = false): int
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return self::FAILURE;
        }

        if ($this->phpunitIsConfiguredForEnvTesting() && ! $force) {
            return self::SUCCESS;
        }

        $dom = $this->loadPhpunitXml($path);
        if (! $dom instanceof \DOMDocument) {
            return self::FAILURE;
        }

        $xpath = new \DOMXPath($dom);
        $varsToComment = ['DB_CONNECTION', 'DB_DATABASE', 'CACHE_STORE', 'SESSION_DRIVER'];

        foreach ($varsToComment as $var) {
            $nodes = $xpath->query("//php/env[@name='{$var}']");
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $env) {
                if (! $env instanceof \DOMElement) {
                    continue;
                }
                $xml = $dom->saveXML($env);
                if ($xml === false) {
                    continue;
                }
                $comment = $dom->createComment(' '.trim($xml).' ');
                $parent = $env->parentNode;
                if ($parent instanceof \DOMNode) {
                    $parent->replaceChild($comment, $env);
                }
            }
        }

        $phpNodes = $dom->getElementsByTagName('php');
        $php = $phpNodes->item(0);
        if ($php !== null) {
            $hasE2EComment = false;
            foreach ($php->childNodes as $child) {
                if ($child instanceof \DOMComment && str_contains($child->data, 'E2E: Omit')) {
                    $hasE2EComment = true;
                    break;
                }
            }
            if (! $hasE2EComment) {
                $e2eComment = $dom->createComment(' E2E: Omit DB/cache env so .env.testing controls them (required for auth ticket sharing) ');
                $php->insertBefore($e2eComment, $php->firstChild);
            }
        }

        return $this->savePhpunitXml($dom, $path) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Add the pest-e2e auth route to CSRF exclusion in bootstrap/app.php.
     */
    private function addCsrfExclusion(): int
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
