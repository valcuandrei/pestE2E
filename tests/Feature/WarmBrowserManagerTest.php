<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\WarmBrowserManager;

/**
 * Integration tests for the warm-browser server lifecycle. Each test spawns
 * a real Node process that runs `resources/js/pest-e2e/warmBrowserServer.mjs`,
 * captures the handshake wsEndpoint, then shuts the server down again. If
 * `@playwright/test` is not installed in the current node_modules the tests
 * are skipped rather than failed — pest-e2e's own suite does not require a
 * fully-installed consumer Playwright.
 */
function skipIfPlaywrightMissing(): void
{
    $node = null;
    exec('command -v node 2>/dev/null', $out, $exit);
    if ($exit !== 0) {
        test()->markTestSkipped('node not on PATH');
    }
    // The pest-e2e package installs @playwright/test via require-dev.
    $vendorPath = dirname(__DIR__, 2).'/vendor/valcuandrei/pest-e2e';
    $consumerPath = dirname(__DIR__, 3);   // ShowMyWork root when running from packages/pestE2E
    $candidates = [
        dirname(__DIR__, 2).'/node_modules/@playwright/test/package.json',
        $consumerPath.'/node_modules/@playwright/test/package.json',
        '/var/www/html/node_modules/@playwright/test/package.json',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return;
        }
    }

    test()->markTestSkipped('@playwright/test not installed in an accessible node_modules');
}

afterEach(function (): void {
    WarmBrowserManager::resetInstances();
});

it('launches a warm browser server on first endpoint() and returns a valid wsEndpoint', function (): void {
    skipIfPlaywrightMissing();

    // Force ShowMyWork's node_modules as CWD so playwright can be resolved.
    $cwd = '/var/www/html';

    $manager = WarmBrowserManager::forCurrentWorker(
        workingDirectory: $cwd,
        startupTimeoutSeconds: 20,
    );

    $endpoint = $manager->endpoint();

    expect($endpoint)->toBeString()
        ->and($endpoint)->toStartWith('ws://')
        ->and($manager->isRunning())->toBeTrue()
        ->and($manager->pid())->toBeInt();

    $manager->shutdown();

    expect($manager->isRunning())->toBeFalse();
});

it('reuses the same wsEndpoint for the same worker across multiple endpoint() calls', function (): void {
    skipIfPlaywrightMissing();

    $manager = WarmBrowserManager::forCurrentWorker(
        workingDirectory: '/var/www/html',
        startupTimeoutSeconds: 20,
    );

    $first = $manager->endpoint();
    $second = $manager->endpoint();
    $third = $manager->endpoint();

    expect($first)->toBe($second)
        ->and($second)->toBe($third);

    $manager->shutdown();
});

it('shutdown() terminates the browser server and leaves no orphan process', function (): void {
    skipIfPlaywrightMissing();

    $manager = WarmBrowserManager::forCurrentWorker(
        workingDirectory: '/var/www/html',
        startupTimeoutSeconds: 20,
    );
    $manager->endpoint();
    $pid = $manager->pid();

    expect($pid)->toBeInt();

    $manager->shutdown();

    // Give the OS a moment to reap.
    usleep(500_000);

    // No process with that PID should remain in this shell's namespace.
    $stillAlive = @posix_kill((int) $pid, 0);
    expect($stillAlive)->toBeFalse();
});

it('stopAll() terminates every worker manager the process has created', function (): void {
    skipIfPlaywrightMissing();

    $a = WarmBrowserManager::forCurrentWorker(
        workingDirectory: '/var/www/html',
        startupTimeoutSeconds: 20,
    );
    $a->endpoint();

    expect($a->isRunning())->toBeTrue();

    WarmBrowserManager::stopAll();
    usleep(500_000);

    expect($a->isRunning())->toBeFalse();
});

it('caches one manager per worker key so forCurrentWorker() is a singleton', function (): void {
    $a = WarmBrowserManager::forCurrentWorker();
    $b = WarmBrowserManager::forCurrentWorker();

    expect($a)->toBe($b);
});
