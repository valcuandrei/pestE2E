<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use ValcuAndrei\PestE2E\Commands\InstallCommand;
use ValcuAndrei\PestE2E\Support\JsPackageManager;
use ValcuAndrei\PestE2E\Tests\Support\FakePublishCommand;
use ValcuAndrei\PestE2E\Tests\Support\MockJsPackageManager;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/pest-e2e-install-'.uniqid((string) mt_rand(), true);
    mkdir($this->tempDir, 0755, true);
    mkdir($this->tempDir.'/tests', 0755, true);
    $this->app->setBasePath($this->tempDir);

    $this->mockJs = new MockJsPackageManager;
    $this->mockJs->hasPlaywright = false;
    $this->mockJs->installReturnsSuccess = true;
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

it('updates Pest.php and publishes when --yes and Playwright not installed', function (): void {
    createPestPhp($this->tempDir);

    $exitCode = runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($this->mockJs->installCallCount)->toBe(1);

    $tagSets = array_column($this->fakePublish->calls, 'tag');
    $tags = $tagSets ? array_merge(...$tagSets) : [];

    expect($tags)->toContain('pest-e2e-config')
        ->and($tags)->toContain('pest-e2e-test-case')
        ->and($tags)->toContain('pest-e2e-js-harness')
        ->and($tags)->toContain('pest-e2e-js-playwright');

    expect(file_get_contents($this->tempDir.'/tests/Pest.php'))->toContain('E2ETestCase');
});

it('does not modify files or publish when --no', function (): void {
    createPestPhp($this->tempDir);
    $original = file_get_contents($this->tempDir.'/tests/Pest.php');

    $exitCode = runInstall(
        args: ['--no' => true],
        argvFlags: ['--no', '--no-interaction']
    );

    expect($exitCode)->toBe(InstallCommand::SUCCESS)
        ->and($this->fakePublish->calls)->toBeEmpty()
        ->and($this->mockJs->installCallCount)->toBe(0)
        ->and(file_get_contents($this->tempDir.'/tests/Pest.php'))->toBe($original);
});

it('does not call installJsPackage when Playwright already installed', function (): void {
    createPestPhp($this->tempDir);
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

it('injects RefreshDatabase when Pest.php contains RefreshDatabase', function (): void {
    createPestPhp($this->tempDir, "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\n\n");
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    $pest = file_get_contents($this->tempDir.'/tests/Pest.php');
    expect($pest)->toContain('RefreshDatabase')
        ->and($pest)->toContain('E2ETestCase::class')
        ->and($pest)->toContain('->use(RefreshDatabase::class)');
});

it('does not duplicate E2ETestCase when already present', function (): void {
    createPestPhp($this->tempDir, "<?php\n\ndeclare(strict_types=1);\n\npest()->extend(Tests\\E2ETestCase::class)->in('Browser');\n");
    $this->mockJs->hasPlaywright = true;

    runInstall(
        args: ['--yes' => true],
        argvFlags: ['--yes', '--no-interaction']
    );

    $count = substr_count(file_get_contents($this->tempDir.'/tests/Pest.php'), 'E2ETestCase::class');
    expect($count)->toBe(1);
});
