<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\Support\JsPackageManager;
use ValcuAndrei\PestE2E\Support\NodeCompileCache;
use ValcuAndrei\PestE2E\Workers\Playwright\PlaywrightWorker;

/**
 * Launch PlaywrightWorker against a stand-in `playwright` binary that reports
 * exactly what it was started with, so the launch contract can be compared
 * with and without the compile cache.
 */
function launchFakePlaywright(string $project, NodeCompileCache $compileCache, array $env = []): array
{
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: $project, env: $env),
        options: new ProcessOptionsDTO,
        testFilter: 'saves the profile (twice)',
        reportDirectory: $project.'/report',
        specPath: 'profile.spec.ts',
    );

    $result = (new PlaywrightWorker(new JsPackageManager, $compileCache))->run($plan);

    /** @var array{argv: list<string>, compileCache: string|null} $seen */
    $seen = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);

    return ['argv' => $seen['argv'], 'compileCache' => $seen['compileCache'], 'exitCode' => $result->exitCode, 'stderr' => $result->stderr];
}

function fakeCompileCache(?string $nodeVersion, string $directory, array $inherited = []): NodeCompileCache
{
    return new NodeCompileCache(
        nodeVersionResolver: static fn (): ?string => $nodeVersion,
        directory: $directory,
        inheritedEnvironment: static fn (string $name): ?string => $inherited[$name] ?? null,
    );
}

beforeEach(function (): void {
    NodeCompileCache::resetState();
    $this->project = sys_get_temp_dir().'/pest-e2e-ncc-worker-'.bin2hex(random_bytes(6));
    mkdir($this->project.'/node_modules/.bin', 0700, true);

    $bin = $this->project.'/node_modules/.bin/playwright';
    file_put_contents($bin, <<<'PHP'
        #!/usr/bin/env php
        <?php
        $cache = getenv('NODE_COMPILE_CACHE');
        echo json_encode(['argv' => array_slice($argv, 1), 'compileCache' => $cache === false ? null : $cache]);
        fwrite(STDERR, 'diagnostics from the runner');
        exit((int) (getenv('FAKE_EXIT_CODE') ?: 0));
        PHP);
    chmod($bin, 0755);

    $this->cacheDirectory = $this->project.'/compile-cache';
});

afterEach(function (): void {
    NodeCompileCache::resetState();
    exec('rm -rf '.escapeshellarg($this->project));
});

it('passes the cache directory to the Playwright process on supported Node runtimes', function (): void {
    $seen = launchFakePlaywright($this->project, fakeCompileCache('24.16.0', $this->cacheDirectory));

    expect($seen['compileCache'])->toBe($this->cacheDirectory);
});

it('does not pass a cache directory on older Node runtimes', function (): void {
    $seen = launchFakePlaywright($this->project, fakeCompileCache('20.19.5', $this->cacheDirectory));

    expect($seen['compileCache'])->toBeNull();
});

it('does not pass a cache directory when automatic caching is disabled', function (): void {
    config(['pest-e2e.node_compile_cache.enabled' => false]);

    $seen = launchFakePlaywright($this->project, fakeCompileCache('24.16.0', $this->cacheDirectory));

    expect($seen['compileCache'])->toBeNull();
});

it('passes a target-defined NODE_COMPILE_CACHE through unchanged', function (bool $enabled): void {
    config(['pest-e2e.node_compile_cache.enabled' => $enabled]);

    $seen = launchFakePlaywright($this->project, fakeCompileCache('24.16.0', $this->cacheDirectory), [NodeCompileCache::ENV_VAR => '/chosen/by/the/user']);

    expect($seen['compileCache'])->toBe('/chosen/by/the/user');
})->with(['automatic caching enabled' => [true], 'automatic caching disabled' => [false]]);

it('launches Playwright with identical arguments whether or not the cache is used', function (): void {
    $cached = launchFakePlaywright($this->project, fakeCompileCache('24.16.0', $this->cacheDirectory));
    $uncached = launchFakePlaywright($this->project, fakeCompileCache('20.19.5', $this->cacheDirectory));

    config(['pest-e2e.node_compile_cache.enabled' => false]);
    $disabled = launchFakePlaywright($this->project, fakeCompileCache('24.16.0', $this->cacheDirectory));

    expect($cached['argv'])->toBe([
        'test',
        '--workers=1',
        '--grep',
        preg_quote('saves the profile (twice)'),
        '--output',
        $this->project.'/report',
        '--reporter',
        'json',
        'profile.spec.ts',
    ])->and($uncached['argv'])->toBe($cached['argv'])
        ->and($disabled['argv'])->toBe($cached['argv']);
});

it('reports the same exit status and error output whether or not the cache is used', function (int $exitCode): void {
    $env = ['FAKE_EXIT_CODE' => (string) $exitCode];

    $cached = launchFakePlaywright($this->project, fakeCompileCache('24.16.0', $this->cacheDirectory), $env);
    $uncached = launchFakePlaywright($this->project, fakeCompileCache('20.19.5', $this->cacheDirectory), $env);

    expect($cached['exitCode'])->toBe($exitCode)
        ->and($uncached['exitCode'])->toBe($exitCode)
        ->and($cached['stderr'])->toBe('diagnostics from the runner')
        ->and($uncached['stderr'])->toBe($cached['stderr']);
})->with([0, 1, 130]);

it('still launches Playwright normally when the cache directory cannot be prepared', function (): void {
    file_put_contents($this->project.'/occupied', 'a regular file');
    $messages = [];
    $compileCache = new NodeCompileCache(
        nodeVersionResolver: static fn (): string => '24.16.0',
        directory: $this->project.'/occupied/cache',
        inheritedEnvironment: static fn (): ?string => null,
        diagnostic: static function (string $message) use (&$messages): void {
            $messages[] = $message;
        },
    );

    $seen = launchFakePlaywright($this->project, $compileCache, ['FAKE_EXIT_CODE' => '1']);

    expect($seen['compileCache'])->toBeNull()
        ->and($seen['exitCode'])->toBe(1)
        ->and($seen['argv'][0])->toBe('test')
        ->and($messages)->toHaveCount(1);
});
