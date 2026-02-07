<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use ValcuAndrei\PestE2E\Plugin;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
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

it('groups entries by parent test name when flushing', function () {
    $store = app(E2EOutputStore::class);
    $store->flush();

    $formatter = new E2EOutputFormatter;

    $linesA = $formatter->buildRunLines(
        target: 'frontend',
        runId: 'run-1',
        ok: true,
        durationSeconds: null,
        stats: null,
        tests: [],
        parentTestName: 'Parent Test',
        extraLines: [],
    );

    $linesB = $formatter->buildRunLines(
        target: 'frontend',
        runId: 'run-2',
        ok: true,
        durationSeconds: null,
        stats: null,
        tests: [],
        parentTestName: 'Parent Test',
        extraLines: [],
    );

    $store->add(
        lines: $linesA,
        type: 'run',
        target: 'frontend',
        runId: 'run-1',
        ok: true,
        durationSeconds: null,
        stats: null,
    );

    $store->add(
        lines: $linesB,
        type: 'run',
        target: 'frontend',
        runId: 'run-2',
        ok: true,
        durationSeconds: null,
        stats: null,
    );

    $output = new BufferedOutput;
    $plugin = new Plugin($output, $store);
    $plugin->addOutput(0);
    $rendered = $output->fetch();

    expect(substr_count($rendered, 'Parent Test'))->toBe(1)
        ->and($rendered)->toContain('└─ E2E › frontend (runId run-1)')
        ->and($rendered)->toContain('└─ E2E › frontend (runId run-2)');
});

it('prints a blank line between grouped and flat output when flushing', function () {
    $store = app(E2EOutputStore::class);
    $store->flush();

    $formatter = new E2EOutputFormatter;
    $groupedLines = $formatter->buildRunLines(
        target: 'frontend',
        runId: 'run-grouped',
        ok: true,
        durationSeconds: null,
        stats: null,
        tests: [],
        parentTestName: 'Parent Test',
        extraLines: [],
    );

    $flatLines = $formatter->buildRunLines(
        target: 'backend',
        runId: 'run-flat',
        ok: true,
        durationSeconds: null,
        stats: null,
        tests: [],
        parentTestName: null,
        extraLines: [],
    );

    $store->add(
        lines: $groupedLines,
        type: 'run',
        target: 'frontend',
        runId: 'run-grouped',
        ok: true,
        durationSeconds: null,
        stats: null,
    );

    $store->add(
        lines: $flatLines,
        type: 'run',
        target: 'backend',
        runId: 'run-flat',
        ok: true,
        durationSeconds: null,
        stats: null,
    );

    $output = new BufferedOutput;
    $plugin = new Plugin($output, $store);
    $plugin->addOutput(0);

    $rendered = $output->fetch();

    expect(substr_count($rendered, 'Parent Test'))->toBe(1)
        ->and($rendered)->toContain('└─ E2E › frontend (runId run-grouped)')
        ->and($rendered)->toContain('PestE2E: target "backend" runId "run-flat"')
        ->and($rendered)->toMatch("/run-grouped.*\n\nPestE2E: target \"backend\" runId \"run-flat\"/s");
});
