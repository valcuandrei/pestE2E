<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\NodeCompileCache;
use ValcuAndrei\PestE2E\Support\ReportPathPolicy;
use ValcuAndrei\PestE2E\Tests\Support\TemporaryDirectories;

/**
 * Build a NodeCompileCache whose surroundings are fully controlled by the test.
 *
 * @param  array<string, string>  $inherited  Variables the child would inherit from the Pest process.
 * @param  list<string>  $messages  Collects diagnostics.
 */
function compileCache(?string $nodeVersion, ?string $directory, array $inherited = [], array &$messages = []): NodeCompileCache
{
    return new NodeCompileCache(
        nodeVersionResolver: static fn (): ?string => $nodeVersion,
        directory: $directory,
        inheritedEnvironment: static fn (string $name): ?string => $inherited[$name] ?? null,
        diagnostic: static function (string $message) use (&$messages): void {
            $messages[] = $message;
        },
    );
}

beforeEach(function (): void {
    NodeCompileCache::resetState();
    $this->scratch = TemporaryDirectories::create('pest-e2e-ncc-test-');
    $this->cacheDirectory = $this->scratch.'/cache';
    $this->originalBasePath = app()->basePath();
    $this->originalTestToken = $_SERVER['TEST_TOKEN'] ?? null;
});

afterEach(function (): void {
    NodeCompileCache::resetState();
    // Restore the real application path first: cleanup refuses to run while
    // the application's report directory resolves inside the fixture.
    app()->setBasePath($this->originalBasePath);
    TemporaryDirectories::remove($this->scratch);

    if ($this->originalTestToken === null) {
        unset($_SERVER['TEST_TOKEN']);
    } else {
        $_SERVER['TEST_TOKEN'] = $this->originalTestToken;
    }
});

it('recognises the first Node release with a compile cache', function (?string $version, bool $supported): void {
    expect(NodeCompileCache::supportsCompileCache($version))->toBe($supported);
})->with([
    'exactly 22.1.0' => ['22.1.0', true],
    'v-prefixed 22.1.0' => ['v22.1.0', true],
    'later 22.x' => ['22.12.0', true],
    'odd-numbered 23' => ['23.0.0', true],
    'current LTS 24' => ['v24.16.0', true],
    'pre-release build of a supported version' => ['v22.1.0-nightly20240501', true],
    '22.0.0, one minor too old' => ['22.0.0', false],
    'Node 21' => ['21.7.3', false],
    'Node 20' => ['v20.19.5', false],
    'Node 18' => ['18.20.4', false],
    'unknown runtime' => [null, false],
    'unparseable output' => ['not a version', false],
    'empty output' => ['', false],
]);

it('enables the cache by default on a supported Node runtime', function (): void {
    $env = compileCache('24.16.0', $this->cacheDirectory)->apply(['APP_URL' => 'http://127.0.0.1:8801'], $this->scratch);

    expect($env)->toBe([
        'APP_URL' => 'http://127.0.0.1:8801',
        NodeCompileCache::ENV_VAR => $this->cacheDirectory,
    ])->and(is_dir($this->cacheDirectory))->toBeTrue();
});

it('creates its directory private to the current user', function (): void {
    compileCache('22.1.0', $this->cacheDirectory)->apply([], $this->scratch);

    expect(fileperms($this->cacheDirectory) & 0777)->toBe(0700);
})->skipOnWindows();

it('leaves the environment untouched on Node runtimes older than 22.1', function (string $version): void {
    $env = compileCache($version, $this->cacheDirectory)->apply(['APP_URL' => 'http://127.0.0.1:8801'], $this->scratch);

    expect($env)->toBe(['APP_URL' => 'http://127.0.0.1:8801'])
        ->and(file_exists($this->cacheDirectory))->toBeFalse();
})->with(['22.0.0', '20.19.5', '18.20.4']);

it('leaves the environment untouched when the Node version cannot be determined', function (): void {
    $env = compileCache(null, $this->cacheDirectory)->apply([], $this->scratch);

    expect($env)->toBe([])
        ->and(file_exists($this->cacheDirectory))->toBeFalse();
});

it('keeps a NODE_COMPILE_CACHE value set on the target environment', function (): void {
    $env = compileCache('24.16.0', $this->cacheDirectory)->apply([NodeCompileCache::ENV_VAR => '/my/own/cache'], $this->scratch);

    expect($env)->toBe([NodeCompileCache::ENV_VAR => '/my/own/cache'])
        ->and(file_exists($this->cacheDirectory))->toBeFalse();
});

it('keeps an explicit request to unset NODE_COMPILE_CACHE for the child', function (): void {
    $env = compileCache('24.16.0', $this->cacheDirectory)->apply([NodeCompileCache::ENV_VAR => null], $this->scratch);

    expect($env)->toBe([NodeCompileCache::ENV_VAR => null]);
});

it('does not shadow a NODE_COMPILE_CACHE value inherited from the Pest process', function (): void {
    $env = compileCache('24.16.0', $this->cacheDirectory, [NodeCompileCache::ENV_VAR => '/inherited/cache'])->apply([], $this->scratch);

    // Nothing is added, so the child inherits the user's value as it always did.
    expect($env)->toBe([])
        ->and(file_exists($this->cacheDirectory))->toBeFalse();
});

it('treats an empty inherited NODE_COMPILE_CACHE as the user turning the cache off', function (): void {
    $env = compileCache('24.16.0', $this->cacheDirectory, [NodeCompileCache::ENV_VAR => ''])->apply([], $this->scratch);

    expect($env)->toBe([]);
});

it('stays out of the way when NODE_DISABLE_COMPILE_CACHE is defined', function (array $env, array $inherited): void {
    expect(compileCache('24.16.0', $this->cacheDirectory, $inherited)->apply($env, $this->scratch))->toBe($env)
        ->and(file_exists($this->cacheDirectory))->toBeFalse();
})->with([
    'on the target environment' => [[NodeCompileCache::DISABLE_ENV_VAR => '1'], []],
    'inherited from the Pest process' => [[], [NodeCompileCache::DISABLE_ENV_VAR => '1']],
]);

it('sets nothing when automatic caching is disabled in config', function (mixed $disabled): void {
    config(['pest-e2e.node_compile_cache.enabled' => $disabled]);

    $env = compileCache('24.16.0', $this->cacheDirectory)->apply(['APP_URL' => 'http://127.0.0.1:8801'], $this->scratch);

    expect($env)->toBe(['APP_URL' => 'http://127.0.0.1:8801'])
        ->and(file_exists($this->cacheDirectory))->toBeFalse();
})->with([
    'boolean false' => [false],
    'string false from an env file' => ['false'],
    'zero' => [0],
]);

it('preserves a user-defined NODE_COMPILE_CACHE when automatic caching is disabled', function (): void {
    config(['pest-e2e.node_compile_cache.enabled' => false]);

    $fromTarget = compileCache('24.16.0', $this->cacheDirectory)->apply([NodeCompileCache::ENV_VAR => '/my/own/cache'], $this->scratch);
    $inherited = compileCache('24.16.0', $this->cacheDirectory, [NodeCompileCache::ENV_VAR => '/inherited/cache'])->apply([], $this->scratch);

    // Explicit value passes through; inherited value is neither removed nor overridden.
    expect($fromTarget)->toBe([NodeCompileCache::ENV_VAR => '/my/own/cache'])
        ->and($inherited)->toBe([]);
});

it('ships with automatic caching enabled', function (): void {
    expect(config('pest-e2e.node_compile_cache.enabled'))->toBeTrue();
});

it('no longer ships the unimplemented js_runner.mode key', function (): void {
    expect(config('pest-e2e.js_runner'))->toBe(['driver' => 'playwright']);
});

it('resolves the same default directory on every invocation', function (): void {
    $first = (new NodeCompileCache)->directory();
    $second = (new NodeCompileCache)->directory();

    expect($first)->toBe($second)
        ->and($first)->toBe(ReportPathPolicy::fallbackDirectory('pest-e2e-node-compile-cache'))
        ->and(str_starts_with($first, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))->toBeTrue()
        ->and($first)->toContain('pest-e2e-node-compile-cache-'.ReportPathPolicy::effectiveUserId().'-');
});

it('gives unrelated projects separate directories', function (): void {
    $projectA = $this->scratch.'/project-a';
    $projectB = $this->scratch.'/project-b';
    mkdir($projectA);
    mkdir($projectB);

    app()->setBasePath($projectA);
    $directoryA = (new NodeCompileCache)->directory();
    $directoryAAgain = (new NodeCompileCache)->directory();

    app()->setBasePath($projectB);
    $directoryB = (new NodeCompileCache)->directory();

    expect($directoryA)->toBe($directoryAAgain)
        ->and($directoryA)->not->toBe($directoryB);
});

it('resolves a symlinked checkout to the same directory as its real path', function (): void {
    $real = $this->scratch.'/real-project';
    $link = $this->scratch.'/linked-project';
    mkdir($real);
    symlink($real, $link);

    app()->setBasePath($real);
    $viaRealPath = (new NodeCompileCache)->directory();

    app()->setBasePath($link);
    $viaSymlink = (new NodeCompileCache)->directory();

    expect($viaSymlink)->toBe($viaRealPath);
})->skipOnWindows();

it('shares one directory between the parallel workers of a project', function (): void {
    $directories = [];

    foreach ([null, '1', '2', '7'] as $token) {
        if ($token === null) {
            unset($_SERVER['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $token;
        }

        $directories[] = (new NodeCompileCache)->directory();
    }

    expect(array_unique($directories))->toHaveCount(1);
});

it('prepares the directory once per process, not once per run', function (): void {
    $cache = compileCache('24.16.0', $this->cacheDirectory);

    $first = $cache->apply([], $this->scratch);
    $inode = fileinode($this->cacheDirectory);
    touch($this->cacheDirectory.'/entry-written-by-node');

    $second = $cache->apply([], $this->scratch);
    $third = compileCache('24.16.0', $this->cacheDirectory)->apply([], $this->scratch);

    expect($second)->toBe($first)
        ->and($third)->toBe($first)
        ->and(fileinode($this->cacheDirectory))->toBe($inode)
        ->and(is_file($this->cacheDirectory.'/entry-written-by-node'))->toBeTrue();
});

it('runs without the cache and says why when the directory cannot be created', function (): void {
    $messages = [];
    file_put_contents($this->scratch.'/occupied', 'a regular file');
    $directory = $this->scratch.'/occupied/cache';
    $cache = compileCache('24.16.0', $directory, [], $messages);

    $first = $cache->apply(['APP_URL' => 'http://127.0.0.1:8801'], $this->scratch);
    $second = $cache->apply(['APP_URL' => 'http://127.0.0.1:8801'], $this->scratch);

    // Same environment as an uncached run; one explanation, not one per test.
    expect($first)->toBe(['APP_URL' => 'http://127.0.0.1:8801'])
        ->and($second)->toBe($first)
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('could not be created')
        ->and($messages[0])->toContain($directory)
        ->and($messages[0])->toContain('NODE_COMPILE_CACHE');
});

it('refuses a cache directory that is a symbolic link', function (): void {
    $messages = [];
    $elsewhere = $this->scratch.'/elsewhere';
    mkdir($elsewhere, 0700);
    symlink($elsewhere, $this->cacheDirectory);

    $env = compileCache('24.16.0', $this->cacheDirectory, [], $messages)->apply([], $this->scratch);

    expect($env)->toBe([])
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('symbolic link');
})->skipOnWindows();

it('refuses a cache directory other users can write to', function (): void {
    $messages = [];
    mkdir($this->cacheDirectory, 0700);
    chmod($this->cacheDirectory, 0777);

    $env = compileCache('24.16.0', $this->cacheDirectory, [], $messages)->apply([], $this->scratch);

    expect($env)->toBe([])
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('writable by other users');
})->skipOnWindows();

it('refuses a cache directory it cannot write to', function (): void {
    $messages = [];
    mkdir($this->cacheDirectory, 0500);

    $env = compileCache('24.16.0', $this->cacheDirectory, [], $messages)->apply([], $this->scratch);

    expect($env)->toBe([])
        ->and($messages[0])->toContain('not writable');
})->skipOnWindows()->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root can write to any directory');

it('probes the node binary the child process will resolve', function (): void {
    $bin = $this->scratch.'/bin';
    mkdir($bin);
    file_put_contents($bin.'/node', "#!/bin/sh\necho v22.1.0\n");
    chmod($bin.'/node', 0755);

    $cache = new NodeCompileCache(directory: $this->cacheDirectory, inheritedEnvironment: static fn (): ?string => null);

    expect($cache->apply(['PATH' => $bin], $this->scratch))->toBe(['PATH' => $bin, NodeCompileCache::ENV_VAR => $this->cacheDirectory]);

    file_put_contents($bin.'/node', "#!/bin/sh\necho v20.19.5\n");
    $oldBin = $this->scratch.'/old-bin';
    mkdir($oldBin);
    copy($bin.'/node', $oldBin.'/node');
    chmod($oldBin.'/node', 0755);

    expect($cache->apply(['PATH' => $oldBin], $this->scratch))->toBe(['PATH' => $oldBin]);
})->skipOnWindows();

it('leaves the environment untouched when no node binary can be run', function (): void {
    $emptyBin = $this->scratch.'/empty-bin';
    mkdir($emptyBin);

    $cache = new NodeCompileCache(directory: $this->cacheDirectory, inheritedEnvironment: static fn (): ?string => null);

    expect($cache->apply(['PATH' => $emptyBin], $this->scratch))->toBe(['PATH' => $emptyBin])
        ->and(file_exists($this->cacheDirectory))->toBeFalse();
});
