<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\Support\AgentOutputAggregator;
use ValcuAndrei\PestE2E\Support\AgentParallelMode;

beforeEach(function (): void {
    AgentOutputAggregator::cleanup();
    unset($_SERVER['PARATEST'], $_SERVER['TEST_TOKEN']);
    putenv('PARATEST');
    putenv('TEST_TOKEN');
});

afterEach(function (): void {
    AgentOutputAggregator::cleanup();
    unset($_SERVER['PARATEST'], $_SERVER['TEST_TOKEN']);
    putenv('PARATEST');
    putenv('TEST_TOKEN');
});

it('detects a parallel coordinator from argv', function (): void {
    expect(AgentParallelMode::isCoordinator(['vendor/bin/pest', '--parallel', 'tests/Browser']))->toBeTrue()
        ->and(AgentParallelMode::isCoordinator(['vendor/bin/pest', '-p', 'tests/Browser']))->toBeTrue();
});

it('does not treat paratest workers as coordinators', function (): void {
    $_SERVER['PARATEST'] = '1';

    expect(AgentParallelMode::isCoordinator(['vendor/bin/pest', '--parallel', 'tests/Browser']))->toBeFalse();
});

it('records entries while a parallel agent run is active', function (): void {
    AgentOutputAggregator::prepareRun();

    AgentOutputAggregator::record(new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-paratest',
        ok: true,
        durationSeconds: 0.1,
        stats: null,
        lines: [],
    ));

    expect(AgentOutputAggregator::collect())->toHaveCount(1)
        ->and(AgentOutputAggregator::collect()[0]->runId)->toBe('run-paratest');
});

it('forwards agent environment variables from the shell', function (): void {
    putenv('PEST_E2E_AGENT_OUTPUT=1');

    expect(AgentParallelMode::forwardableEnvironmentVariables())
        ->toBe(['PEST_E2E_AGENT_OUTPUT' => '1']);

    putenv('PEST_E2E_AGENT_OUTPUT');
});
