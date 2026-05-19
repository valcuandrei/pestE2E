<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\ArtisanTestArgvBridge;
use ValcuAndrei\PestE2E\Support\CliOptions;

beforeEach(function (): void {
    unset($_SERVER['COLLISION_PRINTER_COMPACT'], $_ENV['COLLISION_PRINTER_COMPACT']);
    putenv('COLLISION_PRINTER_COMPACT');

    CliOptions::$browse = false;
    CliOptions::$debug = false;
    CliOptions::$compact = false;
    CliOptions::$parallel = false;
    CliOptions::$agentOutput = false;
    CliOptions::$packageManager = null;
});

it('parses --run-using=npm', function (): void {
    CliOptions::fromArguments(['--run-using=npm', 'tests/Unit/FooTest.php']);

    expect(CliOptions::$packageManager)->toBe('npm');
});

it('parses --run-using=yarn', function (): void {
    CliOptions::fromArguments(['tests/Unit/FooTest.php', '--run-using=yarn']);

    expect(CliOptions::$packageManager)->toBe('yarn');
});

it('parses --run-using=pnpm', function (): void {
    CliOptions::fromArguments(['--run-using=pnpm']);

    expect(CliOptions::$packageManager)->toBe('pnpm');
});

it('parses --run-using=bun', function (): void {
    CliOptions::fromArguments(['--run-using=bun']);

    expect(CliOptions::$packageManager)->toBe('bun');
});

it('ignores invalid --run-using value', function (): void {
    CliOptions::fromArguments(['--run-using=invalid']);

    expect(CliOptions::$packageManager)->toBeNull();
});

it('ignores empty --run-using value', function (): void {
    CliOptions::fromArguments(['--run-using=']);

    expect(CliOptions::$packageManager)->toBeNull();
});

it('detects compact output mode', function (): void {
    CliOptions::fromArguments(['tests/Browser', '--compact']);

    expect(CliOptions::$compact)->toBeTrue()
        ->and(CliOptions::suppressPassedOutput())->toBeTrue();
});

it('detects compact output mode from collision printer env', function (): void {
    $_SERVER['COLLISION_PRINTER_COMPACT'] = 'true';

    CliOptions::fromArguments(['tests/Browser']);

    expect(CliOptions::$compact)->toBeTrue()
        ->and(CliOptions::suppressPassedOutput())->toBeTrue();
});

it('detects agent output mode from cli flag', function (): void {
    CliOptions::fromArguments(['tests/Browser', '--pest-e2e-agent-output']);

    expect(CliOptions::$agentOutput)->toBeTrue()
        ->and(CliOptions::agentOutput())->toBeTrue()
        ->and(CliOptions::suppressPassedOutput())->toBeTrue();
});

it('ensures --no-output is passed in agent output mode', function (): void {
    CliOptions::fromArguments(['tests/Browser', '--pest-e2e-agent-output']);

    $arguments = CliOptions::ensureNoOutput(['tests/Browser', '--pest-e2e-agent-output']);

    expect($arguments)->toContain('--no-output');
});

it('filterArguments removes --pest-e2e-agent-output', function (): void {
    $args = ['tests/Browser', '--pest-e2e-agent-output'];
    $filtered = CliOptions::filterArguments($args);

    expect($filtered)->toBe(['tests/Browser']);
});

it('detects parallel output mode', function (): void {
    CliOptions::fromArguments(['tests/Browser', '--parallel']);

    expect(CliOptions::$parallel)->toBeTrue()
        ->and(CliOptions::suppressPassedOutput())->toBeTrue();
});

it('filterArguments removes --browse, --headed, --debug', function (): void {
    $args = ['tests/FooTest.php', '--browse', '--debug'];
    $filtered = CliOptions::filterArguments($args);

    expect($filtered)->toBe(['tests/FooTest.php']);
});

it('filterArguments removes --run-using=npm', function (): void {
    $args = ['tests/FooTest.php', '--run-using=npm'];
    $filtered = CliOptions::filterArguments($args);

    expect($filtered)->toBe(['tests/FooTest.php']);
});

it('enables browse from PEST_E2E_BROWSE after artisan test argv bridge sets it', function (): void {
    $argv = ['artisan', 'test', 'tests/Browser', '--browse'];

    ArtisanTestArgvBridge::apply($argv);
    CliOptions::fromArguments($argv);

    expect(CliOptions::$browse)->toBeTrue();
});

it('filterArguments keeps test paths and other args', function (): void {
    $args = ['tests/Unit/FooTest.php', '--run-using=yarn', '--filter=foo'];
    $filtered = CliOptions::filterArguments($args);

    expect($filtered)->toBe(['tests/Unit/FooTest.php', '--filter=foo']);
});
