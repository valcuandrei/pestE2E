<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use ValcuAndrei\PestE2E\Plugin;
use ValcuAndrei\PestE2E\Support\AgentOutputAggregator;
use ValcuAndrei\PestE2E\Support\AgentOutputBootstrap;
use ValcuAndrei\PestE2E\Support\CliOptions;

beforeEach(function (): void {
    unset(
        $_SERVER['PEST_E2E_AGENT_OUTPUT'],
        $_SERVER['PEST_E2E_AGENT_OUTPUT_DISABLE'],
        $_SERVER['COLLISION_PRINTER'],
        $_SERVER['COLLISION_PRINTER_COMPACT'],
        $_SERVER['TEST_TOKEN'],
        $_SERVER['PARATEST'],
    );
    putenv('PEST_E2E_AGENT_OUTPUT');
    putenv('PEST_E2E_AGENT_OUTPUT_DISABLE');
    putenv('COLLISION_PRINTER');
    putenv('COLLISION_PRINTER_COMPACT');
    putenv('TEST_TOKEN');
    putenv('PARATEST');

    CliOptions::$agentOutput = false;

    $_SERVER['PEST_E2E_AGENT_OUTPUT_DISABLE'] = '1';

    AgentOutputAggregator::cleanup();
});

it('clears a stale parallel run marker when agent output is not enabled', function (): void {
    AgentOutputAggregator::prepareRun();

    $_SERVER['argv'] = ['vendor/bin/pest', 'tests/Browser', '--parallel'];

    AgentOutputBootstrap::boot();

    expect(AgentOutputAggregator::hasActiveRun())->toBeFalse();
});

it('prepares parallel aggregation when handling agent arguments on a coordinator', function (): void {
    unset($_SERVER['PEST_E2E_AGENT_OUTPUT_DISABLE']);
    putenv('PEST_E2E_AGENT_OUTPUT_DISABLE');

    $_SERVER['PEST_E2E_AGENT_OUTPUT'] = '1';

    $plugin = new Plugin(new BufferedOutput);
    $plugin->handleArguments(['tests/Browser', '--parallel']);

    expect(AgentOutputAggregator::hasActiveRun())->toBeTrue();
});

it('silences pest output and adds --no-output when handling agent arguments', function (): void {
    unset($_SERVER['PEST_E2E_AGENT_OUTPUT_DISABLE']);
    putenv('PEST_E2E_AGENT_OUTPUT_DISABLE');

    $_SERVER['PEST_E2E_AGENT_OUTPUT'] = '1';
    $_SERVER['COLLISION_PRINTER'] = 'DefaultPrinter';

    $plugin = new Plugin(new BufferedOutput);
    $filtered = $plugin->handleArguments(['tests/Browser']);

    expect($filtered)->toBe(['tests/Browser', '--no-output'])
        ->and($_SERVER)->not->toHaveKey('COLLISION_PRINTER')
        ->and(CliOptions::agentOutput())->toBeTrue();
});
