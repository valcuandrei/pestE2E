<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\Plugin;
use ValcuAndrei\PestE2E\Support\CliOptions;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

beforeEach(function (): void {
    CliOptions::$browse = false;
    CliOptions::$debug = false;
    CliOptions::$compact = false;
    CliOptions::$parallel = false;

    app(E2EOutputStore::class)->flush();
    app(E2EOutputStore::class)->flushPerTestEntries();
});

it('flushes output store via the Pest plugin', function () {
    $store = app(E2EOutputStore::class);

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
    $plugin = new Plugin($output);
    $exitCode = $plugin->addOutput(0);
    $rendered = $output->fetch();

    expect($exitCode)->toBe(0)
        ->and($rendered)->toContain('first line')
        ->and($rendered)->toContain('second line')
        ->and(app(E2EOutputStore::class)->flush())->toBe([]);
});

it('suppresses passed e2e output in compact mode', function (): void {
    $store = app(E2EOutputStore::class);
    CliOptions::fromArguments(['--compact']);

    $store->add(
        lines: ['passed e2e line'],
        type: 'run',
        target: 'frontend',
        runId: 'run-compact-pass',
        ok: true,
        durationSeconds: 0.12,
        stats: null,
    );

    $output = new BufferedOutput;
    (new Plugin($output))->addOutput(0);

    expect($output->fetch())->toBe('')
        ->and(app(E2EOutputStore::class)->flush())->toBe([]);
});

it('keeps failed e2e output in compact mode', function (): void {
    $store = app(E2EOutputStore::class);
    CliOptions::fromArguments(['--compact']);

    $store->add(
        lines: ['failed e2e line'],
        type: 'run',
        target: 'frontend',
        runId: 'run-compact-fail',
        ok: false,
        durationSeconds: 0.12,
        stats: null,
    );

    $output = new BufferedOutput;
    (new Plugin($output))->addOutput(1);

    expect($output->fetch())->toContain('failed e2e line');
});

it('suppresses passed e2e output in parallel mode', function (): void {
    $store = app(E2EOutputStore::class);
    CliOptions::fromArguments(['--parallel']);

    $store->add(
        lines: ['passed parallel e2e line'],
        type: 'run',
        target: 'frontend',
        runId: 'run-parallel-pass',
        ok: true,
        durationSeconds: 0.12,
        stats: null,
    );

    $output = new BufferedOutput;
    (new Plugin($output))->addOutput(0);

    expect($output->fetch())->toBe('');
});

it('suppresses passed per-test e2e output in compact mode', function (): void {
    $store = app(E2EOutputStore::class);
    CliOptions::fromArguments(['--compact']);

    $store->putForTest('test-id', new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-compact-per-test',
        ok: true,
        durationSeconds: 0.12,
        stats: null,
        lines: ['parent test', 'passed nested e2e line'],
    ));

    $output = new BufferedOutput;
    (new Plugin($output))->addOutput(0);

    expect($output->fetch())->toBe('')
        ->and(app(E2EOutputStore::class)->getAllPerTestEntries())->toBe([]);
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
    $plugin = new Plugin($output);
    $plugin->addOutput(0);
    $rendered = $output->fetch();
    $branchPrefix = E2EOutputFormatter::BASE_INDENT.E2EOutputFormatter::BRANCH_PREFIX;

    expect(substr_count($rendered, 'Parent Test'))->toBeGreaterThanOrEqual(1)
        ->and($rendered)->toContain($branchPrefix.'E2E › frontend (runId run-1)')
        ->and($rendered)->toContain($branchPrefix.'E2E › frontend (runId run-2)');
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
    $plugin = new Plugin($output);
    $plugin->addOutput(0);

    $rendered = $output->fetch();
    $branchPrefix = E2EOutputFormatter::BASE_INDENT.E2EOutputFormatter::BRANCH_PREFIX;

    expect(substr_count($rendered, 'Parent Test'))->toBe(1)
        ->and($rendered)->toContain($branchPrefix.'E2E › frontend (runId run-grouped)')
        ->and($rendered)->toContain('PestE2E: target "backend" runId "run-flat"')
        ->and($rendered)->toMatch('/run-grouped.*[\r\n]{2,}.*PestE2E: target "backend" runId "run-flat"/s');
});
