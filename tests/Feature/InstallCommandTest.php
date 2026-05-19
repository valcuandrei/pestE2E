<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use ValcuAndrei\PestE2E\Commands\InstallCommand;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallPlan;
use ValcuAndrei\PestE2E\Install\Steps\UpdatePestConfigStep;
use ValcuAndrei\PestE2E\PHPUnit\PestE2EPhpunitExtension;
use ValcuAndrei\PestE2E\Support\CliOptions;
use ValcuAndrei\PestE2E\Support\JsPackageManager;
use ValcuAndrei\PestE2E\Tests\Support\FakePublishCommand;
use ValcuAndrei\PestE2E\Tests\Support\MockJsPackageManager;

function createInstallTestEnv(string $tempDir): void
{
    file_put_contents($tempDir.'/.env', "APP_KEY=base64:test\nAPP_ENV=local\nDB_CONNECTION=sqlite\nDB_DATABASE=laravel\nCACHE_STORE=array\nSESSION_DRIVER=array\n");
    mkdir($tempDir.'/database', 0755, true);
    file_put_contents(
        $tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><php><env name="DB_CONNECTION" value="sqlite"/><env name="DB_DATABASE" value="laravel"/><env name="CACHE_STORE" value="array"/><env name="SESSION_DRIVER" value="array"/></php></phpunit>'
    );
}

function createBootstrapAppWithEncryptCookies(string $tempDir): void
{
    mkdir($tempDir.'/bootstrap', 0755, true);
    file_put_contents(
        $tempDir.'/bootstrap/app.php',
        <<<'PHP'
<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function ($middleware) {
        $middleware->encryptCookies(except: []);
    });
PHP
    );
}

function createBootstrapAppWithoutCsrfHook(string $tempDir): void
{
    mkdir($tempDir.'/bootstrap', 0755, true);
    file_put_contents(
        $tempDir.'/bootstrap/app.php',
        <<<'PHP'
<?php

// No encryptCookies — InstallCommand cannot patch this file
return [];
PHP
    );
}

function createSailComposeEnvironment(string $tempDir, string $composeFilename = 'compose.yml'): void
{
    file_put_contents($tempDir.'/composer.json', json_encode([
        'require-dev' => ['laravel/sail' => '^1.41'],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    mkdir($tempDir.'/vendor/laravel/sail', 0755, true);
    file_put_contents($tempDir.'/'.$composeFilename, <<<'YAML'
services:
    laravel.test:
        image: sail-placeholder
        volumes:
            - '.:/var/www/html'

YAML);
}

function assertPhpunitExtensionRegistered(string $phpunitPath): void
{
    $dom = new DOMDocument;
    $dom->preserveWhiteSpace = false;
    expect(@$dom->load($phpunitPath))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//extensions/bootstrap[@class='".PestE2EPhpunitExtension::class."']");
    expect($nodes !== false && $nodes->length > 0)->toBeTrue();
}

function assertPhpunitEnvVarsCommented(string $phpunitPath): void
{
    $dom = new DOMDocument;
    $dom->preserveWhiteSpace = false;
    expect(@$dom->load($phpunitPath))->toBeTrue();
    $xpath = new DOMXPath($dom);
    foreach (['DB_CONNECTION', 'DB_DATABASE', 'CACHE_STORE', 'SESSION_DRIVER'] as $var) {
        $nodes = $xpath->query("//php/env[@name='{$var}']");
        expect($nodes === false || $nodes->length === 0)->toBeTrue();
    }
}

function assertPhpunitBrowserTestsuitePresent(string $phpunitPath): void
{
    $dom = new DOMDocument;
    $dom->preserveWhiteSpace = false;
    expect(@$dom->load($phpunitPath))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//testsuite[@name='Browser']/directory[text()='tests/Browser']");
    expect($nodes !== false && $nodes->length > 0)->toBeTrue();
}

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/pest-e2e-install-'.uniqid((string) mt_rand(), true);
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/tests', 0755, true);
    $this->app->setBasePath($this->tempDir);

    $this->mockJs = new MockJsPackageManager;
    $this->mockJs->hasPlaywright = false;
    $this->mockJs->installReturnsSuccess = true;
    $this->mockJs->availablePackageManagersOverride = ['npm'];
    $this->app->instance(JsPackageManager::class, $this->mockJs);

    $this->fakePublish = new FakePublishCommand;
    $kernel = $this->app->make(Kernel::class);
    $kernel->bootstrap();
    $ref = new ReflectionMethod($kernel, 'getArtisan');
    $ref->setAccessible(true);
    $ref->invoke($kernel)->add($this->fakePublish);
});

afterEach(function (): void {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path !== false) {
                $file->isDir() ? @rmdir($path) : @unlink($path);
            }
        }
        @rmdir($this->tempDir);
    }
});

function createPestPhp(string $dir, string $content = "<?php\n\ndeclare(strict_types=1);\n\n"): void
{
    file_put_contents($dir.'/tests/Pest.php', $content);
}

function runInstall(array $args = [], array $argvFlags = [], ?BufferedOutput $output = null): int
{
    $_SERVER['argv'] = array_merge(['artisan', 'pest-e2e:install'], $argvFlags);

    $args = array_merge(['--no-interaction' => true], $args);

    return Artisan::call('pest-e2e:install', $args, $output ?? new BufferedOutput);
}

it('fails when tests/Pest.php is missing', function (): void {
    $output = new BufferedOutput;

    $exitCode = runInstall(
        args: [],
        argvFlags: ['--no-interaction'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE)
        ->and($output->fetch())->toContain('Pest config file not found');
});

it('restores CliOptions package manager after Playwright install', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    createBootstrapAppWithEncryptCookies($this->tempDir);
    CliOptions::$packageManager = 'yarn';

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction'],
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and(CliOptions::$packageManager)->toBe('yarn')
        ->and($this->mockJs->playwrightBrowsersInstallCallCount)->toBe(1);
});

it('updates Pest.php and publishes when --yes and Playwright not installed', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    createBootstrapAppWithEncryptCookies($this->tempDir);

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($this->mockJs->installCallCount)->toBe(1)
        ->and($this->mockJs->playwrightBrowsersInstallCallCount)->toBe(1)
        ->and($output->fetch())->toContain('CSRF exclusion for pest-e2e auth route added successfully');

    $tagSets = array_column($this->fakePublish->calls, 'tag');
    $tags = $tagSets ? array_merge(...$tagSets) : [];

    expect($tags)->toContain('pest-e2e-config')
        ->and($tags)->toContain('pest-e2e-test-case')
        ->and($tags)->toContain('pest-e2e-js-harness')
        ->and($tags)->toContain('pest-e2e-js-playwright');

    expect(file_get_contents($this->tempDir.'/tests/Pest.php'))->toContain('E2ETestCase');

    assertPhpunitEnvVarsCommented($this->tempDir.'/phpunit.xml');
    assertPhpunitExtensionRegistered($this->tempDir.'/phpunit.xml');
    assertPhpunitBrowserTestsuitePresent($this->tempDir.'/phpunit.xml');

    expect(file_get_contents($this->tempDir.'/bootstrap/app.php'))->toContain('pest-e2e/auth/login');
    expect(file_exists($this->tempDir.'/.env.testing'))->toBeTrue();
    expect(file_exists($this->tempDir.'/database/testing.sqlite'))->toBeFalse();

    $pest = file_get_contents($this->tempDir.'/tests/Pest.php');
    expect($pest)->toContain('RefreshDatabase')
        ->and($pest)->toContain("->in('Feature')");
});

it('creates a parallel-safe .env.testing when missing', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--setup-env-testing' => true],
        argvFlags: ['--setup-env-testing', '--no-interaction']
    );

    $env = file_get_contents($this->tempDir.'/.env.testing');

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($env)->toContain('DB_CONNECTION=mysql')
        ->and($env)->toContain('DB_HOST=mysql')
        ->and($env)->toContain('DB_PORT=3306')
        ->and($env)->toContain('DB_DATABASE=testing')
        ->and($env)->toContain('DB_USERNAME=sail')
        ->and($env)->toContain('DB_PASSWORD=password')
        ->and($env)->toContain('SESSION_DRIVER=array')
        ->and($env)->toContain('CACHE_STORE=array')
        ->and($env)->toContain('QUEUE_CONNECTION=sync');
});

it('patches existing .env.testing session cache and queue keys without removing unrelated lines', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    file_put_contents($this->tempDir.'/.env.testing', <<<'ENV'
APP_ENV=testing
# Keep this comment
CUSTOM_VALUE=keep-me
DB_CONNECTION=mysql
DB_HOST=mysql-custom
DB_PORT=3307
DB_DATABASE=custom_testing
DB_USERNAME=custom_user
DB_PASSWORD=secret
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
ENV);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--update-testing-env' => true],
        argvFlags: ['--update-testing-env', '--no-interaction']
    );

    $env = file_get_contents($this->tempDir.'/.env.testing');

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($env)->toContain('# Keep this comment')
        ->and($env)->toContain('CUSTOM_VALUE=keep-me')
        ->and($env)->toContain('DB_HOST=mysql-custom')
        ->and($env)->toContain('DB_PORT=3307')
        ->and($env)->toContain('DB_DATABASE=custom_testing')
        ->and($env)->toContain('DB_USERNAME=custom_user')
        ->and($env)->toContain('DB_PASSWORD=secret')
        ->and($env)->toContain('SESSION_DRIVER=array')
        ->and($env)->toContain('CACHE_STORE=array')
        ->and($env)->toContain('QUEUE_CONNECTION=sync');
});

it('preserves existing pgsql database credentials when updating .env.testing', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    file_put_contents($this->tempDir.'/.env.testing', <<<'ENV'
APP_ENV=testing
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5433
DB_DATABASE=tenant_testing
DB_USERNAME=tenant_user
DB_PASSWORD=tenant_password
ENV);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--update-testing-env' => true],
        argvFlags: ['--update-testing-env', '--no-interaction']
    );

    $env = file_get_contents($this->tempDir.'/.env.testing');

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($env)->toContain('DB_CONNECTION=pgsql')
        ->and($env)->toContain('DB_HOST=postgres')
        ->and($env)->toContain('DB_PORT=5433')
        ->and($env)->toContain('DB_DATABASE=tenant_testing')
        ->and($env)->toContain('DB_USERNAME=tenant_user')
        ->and($env)->toContain('DB_PASSWORD=tenant_password')
        ->and($env)->toContain('SESSION_DRIVER=array')
        ->and($env)->toContain('CACHE_STORE=array')
        ->and($env)->toContain('QUEUE_CONNECTION=sync');
});

it('warns and leaves sqlite database settings unchanged without consent', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    file_put_contents($this->tempDir.'/.env.testing', <<<'ENV'
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE=testing
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
ENV);
    $this->mockJs->hasPlaywright = true;

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: ['--update-testing-env' => true],
        argvFlags: ['--update-testing-env', '--no-interaction'],
        output: $output
    );

    $env = file_get_contents($this->tempDir.'/.env.testing');

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($output->fetch())->toContain('DB_CONNECTION=sqlite is not recommended')
        ->and($env)->toContain('DB_CONNECTION=sqlite')
        ->and($env)->toContain('DB_DATABASE=testing')
        ->and($env)->toContain('SESSION_DRIVER=array')
        ->and($env)->toContain('CACHE_STORE=array')
        ->and($env)->toContain('QUEUE_CONNECTION=sync');
});

it('switches sqlite .env.testing to Sail MySQL defaults when forced', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    file_put_contents($this->tempDir.'/.env.testing', <<<'ENV'
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE=testing
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
ENV);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--update-testing-env' => true, '--force' => true],
        argvFlags: ['--update-testing-env', '--force', '--no-interaction']
    );

    $env = file_get_contents($this->tempDir.'/.env.testing');

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($env)->toContain('DB_CONNECTION=mysql')
        ->and($env)->toContain('DB_HOST=mysql')
        ->and($env)->toContain('DB_PORT=3306')
        ->and($env)->toContain('DB_DATABASE=testing')
        ->and($env)->toContain('DB_USERNAME=sail')
        ->and($env)->toContain('DB_PASSWORD=password');
});

it('registers Pest E2E PHPUnit extension when --no but phpunit.xml exists', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $originalPest = file_get_contents($this->tempDir.'/tests/Pest.php');

    $exitCode = runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($this->fakePublish->calls)->toBeEmpty()
        ->and($this->mockJs->installCallCount)->toBe(0)
        ->and(file_get_contents($this->tempDir.'/tests/Pest.php'))->toBe($originalPest);

    assertPhpunitExtensionRegistered($this->tempDir.'/phpunit.xml');
    assertPhpunitBrowserTestsuitePresent($this->tempDir.'/phpunit.xml');
});

it('warns when CSRF exclusion cannot be applied', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    createBootstrapAppWithoutCsrfHook($this->tempDir);

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($output->fetch())->toContain('Could not add CSRF exclusion');
});

it('does not call installJsPackage when Playwright already installed', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($this->mockJs->installCallCount)->toBe(0);

    $tagSets = array_column($this->fakePublish->calls, 'tag');
    $tags = $tagSets ? array_merge(...$tagSets) : [];

    expect($tags)->toContain('pest-e2e-js-playwright');
});

it('injects DatabaseMigrations for E2E Browser tests', function (): void {
    createPestPhp($this->tempDir, "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\n\n");
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    $pest = file_get_contents($this->tempDir.'/tests/Pest.php');
    expect($pest)->toContain('DatabaseMigrations')
        ->and($pest)->toContain('E2ETestCase::class')
        ->and($pest)->toContain('->use(DatabaseMigrations::class)');
});

it('adds use DatabaseMigrations when Pest.php lacks declare(strict_types) on first line', function (): void {
    createPestPhp($this->tempDir, "<?php\n\n// boot\n");
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect(file_get_contents($this->tempDir.'/tests/Pest.php'))->toContain('use Illuminate\\Foundation\\Testing\\DatabaseMigrations;');
});

it('does not duplicate E2ETestCase when already present', function (): void {
    createPestPhp($this->tempDir, "<?php\n\ndeclare(strict_types=1);\n\npest()->extend(Tests\\E2ETestCase::class)->in('Browser');\n");
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    $count = substr_count(file_get_contents($this->tempDir.'/tests/Pest.php'), 'E2ETestCase::class');
    expect($count)->toBe(1);
});

it('injects detected package manager into published E2ETestCase — yarn', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->mockJs->availablePackageManagersOverride = ['yarn', 'npm'];
    $this->mockJs->detectedLockfilesOverride = ['yarn' => 'yarn.lock'];

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect(file_get_contents($this->tempDir.'/tests/E2ETestCase.php'))->toContain('$e2ePackageManager = \'yarn\'');
});

it('injects pnpm when pnpm lockfile is detected', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->mockJs->availablePackageManagersOverride = ['pnpm', 'npm'];
    $this->mockJs->detectedLockfilesOverride = ['pnpm' => 'pnpm-lock.yaml'];

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect(file_get_contents($this->tempDir.'/tests/E2ETestCase.php'))->toContain('$e2ePackageManager = \'pnpm\'');
});

it('injects bun when bun lockfile is detected', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->mockJs->availablePackageManagersOverride = ['bun', 'npm'];
    $this->mockJs->detectedLockfilesOverride = ['bun' => 'bun.lockb'];

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect(file_get_contents($this->tempDir.'/tests/E2ETestCase.php'))->toContain('$e2ePackageManager = \'bun\'');
});

it('honors --package-manager for E2ETestCase stub over detection', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->mockJs->availablePackageManagersOverride = ['npm'];
    $this->mockJs->detectedLockfilesOverride = [];

    $exitCode = runInstall(
        args: ['--yes' => true, '--package-manager' => 'bun'],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS);
    expect(file_get_contents($this->tempDir.'/tests/E2ETestCase.php'))->toContain('$e2ePackageManager = \'bun\'');
});

it('fails when --package-manager is invalid', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: ['--yes' => true, '--package-manager' => 'invalid-pm'],
        argvFlags: ['--yes', '--no-interaction'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE)
        ->and($output->fetch())->toContain('Invalid --package-manager');
});

it('injects npm when no lockfile detected', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->mockJs->availablePackageManagersOverride = ['npm'];
    $this->mockJs->detectedLockfilesOverride = [];

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect(file_get_contents($this->tempDir.'/tests/E2ETestCase.php'))->toContain('$e2ePackageManager = \'npm\'');
});

it('non-interactive install picks first registration order when multiple PMs lack lockfile match', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->mockJs->availablePackageManagersOverride = ['yarn', 'pnpm'];
    $this->mockJs->detectedLockfilesOverride = [];

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect(file_get_contents($this->tempDir.'/tests/E2ETestCase.php'))->toContain('$e2ePackageManager = \'pnpm\'');
});

it('appends bootstrap into an existing empty extensions element', function (): void {
    createPestPhp($this->tempDir);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents(
        $this->tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><php></php><extensions></extensions></phpunit>'
    );

    runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction']
    );

    assertPhpunitExtensionRegistered($this->tempDir.'/phpunit.xml');
    assertPhpunitBrowserTestsuitePresent($this->tempDir.'/phpunit.xml');
});

it('fails publish base test case when vendor:publish fails', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->fakePublish->failTags = ['pest-e2e-test-case'];

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('fails publish config when vendor:publish fails', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->fakePublish->failTags = ['pest-e2e-config'];

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('fails publish browser tests when vendor:publish fails', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->fakePublish->failTags = ['pest-e2e-browser-tests'];

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('fails publish playwright tests when vendor:publish fails', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->fakePublish->failTags = ['pest-e2e-playwright-tests'];

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('fails publish js harness when vendor:publish fails', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;
    $this->fakePublish->failTags = ['pest-e2e-js-harness'];

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('fails install Playwright when npm install returns unsuccessful', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->installReturnsSuccess = false;

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE)
        ->and($this->mockJs->playwrightBrowsersInstallCallCount)->toBe(0);
});

it('fails install Playwright when browser download returns unsuccessful', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->playwrightBrowsersInstallReturnsSuccess = false;

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE)
        ->and($this->mockJs->installCallCount)->toBe(1)
        ->and($this->mockJs->playwrightBrowsersInstallCallCount)->toBe(1);
});

it('fails after Playwright install when js-playwright publish fails', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->fakePublish->failTags = ['pest-e2e-js-playwright'];

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('warns Playwright is not installed when using --no', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($output->fetch())->toContain('@playwright/test');
});

it('prints harness publish hint when Playwright already installed and harness not published', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: [],
        argvFlags: ['--no-interaction'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS);
    expect($output->fetch())->toContain('pest-e2e-js-harness');
});

it('runs with quiet output without throwing', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    $quietOut = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);
    $exitCode = runInstall(
        args: ['--yes' => true, '--quiet' => true],
        argvFlags: ['--yes', '--no-interaction'],
        output: $quietOut
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS);
    expect($quietOut->fetch())->toBe('');
});

it('skips .env.testing content write when .env is missing but reports success', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    @unlink($this->tempDir.'/.env');
    $this->mockJs->hasPlaywright = true;

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($output->fetch())->toContain('.env file not found');
});

it('fails configure phpunit when phpunit.xml is missing', function (): void {
    createPestPhp($this->tempDir);
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('fails configure phpunit when xml is invalid', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    file_put_contents($this->tempDir.'/phpunit.xml', 'not valid <<<> xml');
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('fails creating testing database when database path is blocked by a file', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    file_put_contents($this->tempDir.'/.env.testing', <<<'ENV'
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE=database/testing.sqlite
ENV);
    rmdir($this->tempDir.'/database');
    touch($this->tempDir.'/database');
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--setup-testing-database' => true],
        argvFlags: ['--setup-testing-database', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::FAILURE);
});

it('does not create database/testing.sqlite when .env.testing uses mysql', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    createBootstrapAppWithEncryptCookies($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and(file_get_contents($this->tempDir.'/.env.testing'))->toContain('DB_CONNECTION=mysql')
        ->and(file_exists($this->tempDir.'/database/testing.sqlite'))->toBeFalse();
});

it('creates database/testing.sqlite when .env.testing uses sqlite', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    file_put_contents($this->tempDir.'/.env.testing', <<<'ENV'
APP_ENV=testing
DB_CONNECTION=sqlite
DB_DATABASE=database/testing.sqlite
SESSION_DRIVER=array
CACHE_STORE=array
QUEUE_CONNECTION=sync
ENV);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--setup-testing-database' => true],
        argvFlags: ['--setup-testing-database', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and(file_exists($this->tempDir.'/database/testing.sqlite'))->toBeTrue();
});

it('injects RefreshDatabase for Feature tests on fresh install', function (): void {
    createPestPhp($this->tempDir, "<?php\n\ndeclare(strict_types=1);\n\n");
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    $pest = file_get_contents($this->tempDir.'/tests/Pest.php');
    expect($pest)->toContain('RefreshDatabase')
        ->and($pest)->toContain("->in('Feature')")
        ->and($pest)->toContain('->use(RefreshDatabase::class)');
});

it('does not duplicate Feature RefreshDatabase when already configured', function (): void {
    createPestPhp($this->tempDir, <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

PHP);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    $pest = file_get_contents($this->tempDir.'/tests/Pest.php');
    expect(substr_count($pest, 'RefreshDatabase::class'))->toBe(1)
        ->and(substr_count($pest, "->in('Feature')"))->toBe(1);
});

it('upgrades uses(TestCase::class) Feature suite to include RefreshDatabase', function (): void {
    createPestPhp($this->tempDir, <<<'PHP'
<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class)->in('Feature');

PHP);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    $pest = file_get_contents($this->tempDir.'/tests/Pest.php');
    expect($pest)->toContain('RefreshDatabase')
        ->and($pest)->toContain("->in('Feature')");
});

it('reports phpunit already configured when env vars are already commented', function (): void {
    createPestPhp($this->tempDir);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents(
        $this->tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><php><!-- <env name="DB_CONNECTION" value="sqlite"/> --></php></phpunit>'
    );
    $this->mockJs->hasPlaywright = true;

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($output->fetch())->toContain('phpunit.xml already configured');

    assertPhpunitBrowserTestsuitePresent($this->tempDir.'/phpunit.xml');
});

it('does not duplicate Browser testsuite when already present in phpunit.xml', function (): void {
    createPestPhp($this->tempDir);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents(
        $this->tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><testsuites><testsuite name="Browser"><directory>tests/Browser</directory></testsuite></testsuites><php></php><extensions></extensions></phpunit>'
    );

    runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction']
    );

    $dom = new DOMDocument;
    expect(@$dom->load($this->tempDir.'/phpunit.xml'))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//testsuite[@name='Browser']");
    expect($nodes !== false ? $nodes->length : 0)->toBe(1);
});

it('idempotently skips CSRF patch when route is already excluded', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    createBootstrapAppWithEncryptCookies($this->tempDir);
    $app = file_get_contents($this->tempDir.'/bootstrap/app.php');
    file_put_contents(
        $this->tempDir.'/bootstrap/app.php',
        str_replace(
            'encryptCookies(except: []);',
            "encryptCookies(except: []);\n        \$middleware->validateCsrfTokens(except: ['/pest-e2e/auth/login']);",
            $app
        )
    );
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS);
});

it('fails addCsrfExclusion when bootstrap app.php is missing', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS);
});

it('accepts --unattended like --yes for publish flags', function (): void {
    createPestPhp($this->tempDir);
    createInstallTestEnv($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--unattended' => true],
        argvFlags: ['--unattended', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS);
    $tagSets = array_column($this->fakePublish->calls, 'tag');
    $tags = $tagSets ? array_merge(...$tagSets) : [];
    expect($tags)->toContain('pest-e2e-config');
});

it('updatePestConfig returns FAILURE when Pest.php is missing', function (): void {
    createPestPhp($this->tempDir);
    unlink($this->tempDir.'/tests/Pest.php');

    $command = app(InstallCommand::class);
    $ctx = new InstallContext(
        new InstallPlan(false, false, false, false, false, false, false, false, false, false, false, false, false),
        $command,
        new ArrayInput([]),
        new NullOutput,
        $this->mockJs,
        false,
        false,
        static fn (array $tags, bool $force): int => $command->call('vendor:publish', array_merge(['--tag' => $tags], $force ? ['--force' => true] : [])),
        static fn (string $name): mixed => $command->option($name),
    );

    $step = new UpdatePestConfigStep;
    $method = new ReflectionMethod(UpdatePestConfigStep::class, 'applyPestConfigUpdate');
    $method->setAccessible(true);

    expect($method->invoke($step, $ctx))->toBe(InstallCommand::FAILURE);
});

it('does not insert duplicate E2E phpunit comment when already present', function (): void {
    createPestPhp($this->tempDir);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents(
        $this->tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><php><!-- E2E: Omit manual --><env name="APP_ENV" value="testing"/></php></phpunit>'
    );
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--configure-phpunit' => true, '--force' => true],
        argvFlags: ['--configure-phpunit', '--force', '--no-interaction']
    );

    expect(substr_count(file_get_contents($this->tempDir.'/phpunit.xml'), 'E2E: Omit'))->toBe(1);
});

it('reuses cached Pest.php content in getPestPhp after first read', function (): void {
    createPestPhp($this->tempDir, "<?php\necho 'v1';\n");
    $command = app(InstallCommand::class);

    $ctx = new InstallContext(
        new InstallPlan(false, false, false, false, false, false, false, false, false, false, false, false, false),
        $command,
        new ArrayInput([]),
        new NullOutput,
        $this->mockJs,
        false,
        false,
        static fn (array $tags, bool $force): int => $command->call('vendor:publish', array_merge(['--tag' => $tags], $force ? ['--force' => true] : [])),
        static fn (string $name): mixed => $command->option($name),
    );

    expect($ctx->getPestPhp())->toContain('v1');

    file_put_contents($this->tempDir.'/tests/Pest.php', "<?php\necho 'v2';\n");
    expect($ctx->getPestPhp())->toContain('v1');
});

it('merges WSLg headed-mode config into the resolved Sail compose file', function (): void {
    createPestPhp($this->tempDir);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents(
        $this->tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><php></php><extensions></extensions></phpunit>'
    );
    createSailComposeEnvironment($this->tempDir, 'docker-compose.yml');
    $this->mockJs->hasPlaywright = true;

    $exitCode = runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction', '--sail-wslg-headed']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS);
    $yaml = file_get_contents($this->tempDir.'/docker-compose.yml');
    expect($yaml)->toContain('DISPLAY')
        ->and($yaml)->toContain('WAYLAND_DISPLAY')
        ->and($yaml)->toContain('/mnt/wslg:/mnt/wslg')
        ->and($yaml)->toContain('/tmp/.X11-unix:/tmp/.X11-unix');
});

it('prefers compose.yml over docker-compose.yml when both exist', function (): void {
    createPestPhp($this->tempDir);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents(
        $this->tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><php></php><extensions></extensions></phpunit>'
    );
    createSailComposeEnvironment($this->tempDir, 'compose.yml');
    file_put_contents($this->tempDir.'/docker-compose.yml', "services:\n    other:\n        image: other\n");
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction', '--sail-wslg-headed']
    );

    expect(file_get_contents($this->tempDir.'/compose.yml'))->toContain('DISPLAY');
    expect(file_get_contents($this->tempDir.'/docker-compose.yml'))->not->toContain('DISPLAY');
});

it('does not merge Sail compose when laravel.test is absent', function (): void {
    createPestPhp($this->tempDir);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents(
        $this->tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><php></php><extensions></extensions></phpunit>'
    );
    file_put_contents($this->tempDir.'/composer.json', json_encode([
        'require-dev' => ['laravel/sail' => '^1.41'],
    ], JSON_THROW_ON_ERROR));
    mkdir($this->tempDir.'/vendor/laravel/sail', 0755, true);
    file_put_contents($this->tempDir.'/compose.yml', "services:\n    mysql:\n        image: mysql\n");
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction', '--sail-wslg-headed']
    );

    expect(file_get_contents($this->tempDir.'/compose.yml'))->not->toContain('DISPLAY');
});

it('reports Sail compose already has WSLg settings after merge', function (): void {
    createPestPhp($this->tempDir);
    file_put_contents($this->tempDir.'/.env', "APP_KEY=base64:test\n");
    mkdir($this->tempDir.'/database', 0755, true);
    file_put_contents(
        $this->tempDir.'/phpunit.xml',
        '<?xml version="1.0"?><phpunit><php></php><extensions></extensions></phpunit>'
    );
    createSailComposeEnvironment($this->tempDir);
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction', '--sail-wslg-headed']
    );

    $output = new BufferedOutput;
    $exitCode = runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction', '--sail-wslg-headed'],
        output: $output
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($output->fetch())->toContain('already includes WSLg');
});
