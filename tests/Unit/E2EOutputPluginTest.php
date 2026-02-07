<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use ValcuAndrei\PestE2E\Plugin;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

it('flushes output store via the Pest plugin', function () {
    $store = app(E2EOutputStore::class);
    $store->flush();

    $store->add(
        lines: ['first line', 'second line'],
        type: 'run',
        target: 'frontend',
        runId: 'run-123',
        ok: true,
        durationSeconds: 0.12,
        stats: null,
    );

    $output = new BufferedOutput;
    $plugin = new Plugin($output, $store);
    $exitCode = $plugin->addOutput(0);
    $rendered = $output->fetch();

    expect($exitCode)->toBe(0)
        ->and($rendered)->toContain('first line')
        ->and($rendered)->toContain('second line')
        ->and($store->isEmpty())->toBeTrue();
});
