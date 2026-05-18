<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\Support\AgentOutputAggregator;

beforeEach(function (): void {
    AgentOutputAggregator::cleanup();
});

afterEach(function (): void {
    AgentOutputAggregator::cleanup();
});

it('removes dotfile markers when cleaning up', function (): void {
    AgentOutputAggregator::prepareRun();

    expect(AgentOutputAggregator::hasActiveRun())->toBeTrue();

    AgentOutputAggregator::cleanup();

    expect(AgentOutputAggregator::hasActiveRun())->toBeFalse();
});

it('records and collects entries from parallel worker files', function (): void {
    $_SERVER['TEST_TOKEN'] = '2';
    putenv('TEST_TOKEN=2');

    AgentOutputAggregator::prepareRun();

    expect(AgentOutputAggregator::hasActiveRun())->toBeTrue();

    AgentOutputAggregator::record(new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-worker-2',
        ok: true,
        durationSeconds: 0.5,
        stats: new JsonReportStatsDTO(passed: 1, failed: 0, skipped: 0, durationMs: 500),
        lines: [],
        reportDirectory: '/tmp/reports/frontend/run-worker-2',
    ));

    unset($_SERVER['TEST_TOKEN']);
    putenv('TEST_TOKEN');

    $entries = AgentOutputAggregator::collect();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->target)->toBe('frontend')
        ->and($entries[0]->runId)->toBe('run-worker-2')
        ->and($entries[0]->stats?->passed)->toBe(1);
});

it('preserves failure details when collecting parallel worker files', function (): void {
    $_SERVER['TEST_TOKEN'] = '3';
    putenv('TEST_TOKEN=3');

    AgentOutputAggregator::prepareRun();

    AgentOutputAggregator::record(new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-fail',
        ok: false,
        durationSeconds: 1.0,
        stats: new JsonReportStatsDTO(passed: 0, failed: 1, skipped: 0, durationMs: 1000),
        lines: [],
        reportDirectory: '/tmp/reports/frontend/run-fail',
        phpTestFile: 'tests/Browser/ExampleTest.php',
        phpTestName: 'example test',
        failures: [
            [
                'name' => 'Example spec',
                'js_file' => 'resources/js/e2e/example.spec.ts',
                'message' => 'Assertion failed',
                'stack' => 'at example.spec.ts:10:5',
            ],
        ],
    ));

    $entries = AgentOutputAggregator::collect();

    expect($entries[0]->phpTestFile)->toBe('tests/Browser/ExampleTest.php')
        ->and($entries[0]->failures[0]['js_file'])->toBe('resources/js/e2e/example.spec.ts')
        ->and($entries[0]->failures[0]['message'])->toBe('Assertion failed');
});
