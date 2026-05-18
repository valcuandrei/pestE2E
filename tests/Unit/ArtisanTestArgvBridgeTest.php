<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\ArtisanTestArgvBridge;

beforeEach(function (): void {
    foreach ([
        'PEST_E2E_AGENT_OUTPUT',
        'PEST_E2E_BROWSE',
        'PEST_E2E_DEBUG',
        'PEST_E2E_PACKAGE_MANAGER',
    ] as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }
});

it('strips pest-e2e flags from artisan test argv and maps them to env', function (): void {
    $argv = ['artisan', 'test', '--parallel', '--pest-e2e-agent-output', '--browse'];

    ArtisanTestArgvBridge::apply($argv);

    expect($argv)->toBe(['artisan', 'test', '--parallel'])
        ->and($_SERVER['PEST_E2E_AGENT_OUTPUT'] ?? null)->toBe('1')
        ->and($_SERVER['PEST_E2E_BROWSE'] ?? null)->toBe('1');
});

it('maps --run-using to package manager env', function (): void {
    $argv = ['artisan', 'test', '--run-using=pnpm'];

    ArtisanTestArgvBridge::apply($argv);

    expect($argv)->toBe(['artisan', 'test'])
        ->and($_SERVER['PEST_E2E_PACKAGE_MANAGER'] ?? null)->toBe('pnpm');
});

it('ignores pest-e2e flags for non-test artisan commands', function (): void {
    $argv = ['artisan', 'pest-e2e:install', '--pest-e2e-agent-output'];

    ArtisanTestArgvBridge::apply($argv);

    expect($argv)->toBe(['artisan', 'pest-e2e:install', '--pest-e2e-agent-output'])
        ->and($_SERVER)->not->toHaveKey('PEST_E2E_AGENT_OUTPUT');
});

it('updates $_SERVER argv when no argv argument is passed', function (): void {
    $_SERVER['argv'] = ['artisan', 'test', '--pest-e2e-json'];

    ArtisanTestArgvBridge::apply();

    expect($_SERVER['argv'])->toBe(['artisan', 'test'])
        ->and($_SERVER['PEST_E2E_AGENT_OUTPUT'] ?? null)->toBe('1');
});
