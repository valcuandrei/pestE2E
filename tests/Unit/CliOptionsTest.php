<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\CliOptions;

beforeEach(function (): void {
    CliOptions::$browse = false;
    CliOptions::$debug = false;
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

it('filterArguments keeps test paths and other args', function (): void {
    $args = ['tests/Unit/FooTest.php', '--run-using=yarn', '--filter=foo'];
    $filtered = CliOptions::filterArguments($args);

    expect($filtered)->toBe(['tests/Unit/FooTest.php', '--filter=foo']);
});
