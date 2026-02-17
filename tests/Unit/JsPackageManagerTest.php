<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\JsPackageManager;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/pest-e2e-js-'.uniqid((string) mt_rand(), true);
    mkdir($this->tempDir, 0755, true);
    $this->app->setBasePath($this->tempDir);
    $this->manager = new JsPackageManager;
});

afterEach(function (): void {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->tempDir);
    }
});

function withFakeBin(string $tempDir, callable $fn): void
{
    $fakeBin = $tempDir.'/fakebin';
    if (! is_dir($fakeBin)) {
        mkdir($fakeBin, 0755, true);
    }

    $savedPath = getenv('PATH');

    putenv('PATH='.$fakeBin.':/usr/bin:/bin');

    try {
        $fn();
    } finally {
        putenv('PATH='.$savedPath);
    }
}

it('getPackageJson returns empty array when package.json is missing and caches on second call', function (): void {
    $first = $this->manager->getPackageJson();
    $second = $this->manager->getPackageJson();

    expect($first)->toBe([])
        ->and($second)->toBe([])
        ->and($first)->toBe($second);
});

it('getPackageJson returns decoded content when package.json exists', function (): void {
    $data = ['name' => 'test-app', 'dependencies' => ['lodash' => '^4.0']];
    file_put_contents($this->tempDir.'/package.json', json_encode($data, JSON_THROW_ON_ERROR));

    $result = $this->manager->getPackageJson();

    expect($result)->toBe($data);
});

it('getInstalledPackages returns empty when node_modules is missing', function (): void {
    $result = $this->manager->getInstalledPackages();

    expect($result)->toBe([]);
});

it('getInstalledPackages detects unscoped packages', function (): void {
    mkdir($this->tempDir.'/node_modules', 0755, true);
    mkdir($this->tempDir.'/node_modules/lodash', 0755, true);
    mkdir($this->tempDir.'/node_modules/vue', 0755, true);

    $result = $this->manager->getInstalledPackages();

    expect($result)->toHaveKey('lodash')
        ->and($result)->toHaveKey('vue')
        ->and($result['lodash'])->toBeTrue()
        ->and($result['vue'])->toBeTrue();
});

it('getInstalledPackages detects scoped packages', function (): void {
    mkdir($this->tempDir.'/node_modules', 0755, true);
    mkdir($this->tempDir.'/node_modules/@scope', 0755, true);
    mkdir($this->tempDir.'/node_modules/@scope/package-a', 0755, true);
    mkdir($this->tempDir.'/node_modules/@scope/package-b', 0755, true);

    $result = $this->manager->getInstalledPackages();

    expect($result)->toHaveKey('@scope/package-a')
        ->and($result)->toHaveKey('@scope/package-b')
        ->and($result['@scope/package-a'])->toBeTrue()
        ->and($result['@scope/package-b'])->toBeTrue();
});

it('detectedLockfiles returns correct matches for each lockfile', function (): void {
    file_put_contents($this->tempDir.'/pnpm-lock.yaml', '');
    file_put_contents($this->tempDir.'/yarn.lock', '');
    file_put_contents($this->tempDir.'/package-lock.json', '');
    file_put_contents($this->tempDir.'/bun.lockb', '');

    $result = $this->manager->detectedLockfiles();

    expect($result)->toHaveKey('pnpm')
        ->and($result)->toHaveKey('yarn')
        ->and($result)->toHaveKey('npm')
        ->and($result)->toHaveKey('bun')
        ->and($result['pnpm'])->toBe('pnpm-lock.yaml')
        ->and($result['yarn'])->toBe('yarn.lock')
        ->and($result['npm'])->toBe('package-lock.json')
        ->and($result['bun'])->toBe('bun.lockb');
});

it('detectedLockfiles returns only existing lockfiles', function (): void {
    file_put_contents($this->tempDir.'/pnpm-lock.yaml', '');

    $result = $this->manager->detectedLockfiles();

    expect($result)->toHaveKey('pnpm')
        ->and($result)->not->toHaveKey('yarn')
        ->and($result)->not->toHaveKey('npm')
        ->and($result)->not->toHaveKey('bun');
});

it('getPackageManager returns locked but unavailable when lockfile exists and binary not available', function (): void {
    file_put_contents($this->tempDir.'/pnpm-lock.yaml', '');

    withFakeBin($this->tempDir, function () {
        $result = $this->manager->getPackageManager();

        expect($result)->not->toBeFalse()
            ->and($result)->toHaveKey('pnpm')
            ->and($result['pnpm']['locked'])->toBeTrue()
            ->and($result['pnpm']['available'])->toBeFalse();
    });
});

it('getPackageManager returns false when no lockfiles and no binaries', function (): void {
    withFakeBin($this->tempDir, function () {
        $result = $this->manager->getPackageManager();
        expect($result)->toBeFalse();
    });
});

it('activePackageManager returns false when all package managers unavailable', function (): void {
    file_put_contents($this->tempDir.'/pnpm-lock.yaml', '');

    withFakeBin($this->tempDir, function () {
        $result = $this->manager->activePackageManager();
        expect($result)->toBeFalse();
    });
});
