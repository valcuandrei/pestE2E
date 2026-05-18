<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\AgentOutput;

beforeEach(function (): void {
    foreach ([
        'PAO_DISABLE',
        'PAO_FORCE',
        'PEST_E2E_AGENT_OUTPUT',
        'PEST_E2E_AGENT_OUTPUT_DISABLE',
        'CURSOR_AGENT',
        'AI_AGENT',
    ] as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }
});

it('is disabled when PAO_DISABLE is set', function (): void {
    $_SERVER['PAO_DISABLE'] = '1';
    $_SERVER['CURSOR_AGENT'] = '1';

    expect(AgentOutput::enabled())->toBeFalse();
});

it('is enabled when PAO_FORCE is set', function (): void {
    $_SERVER['PAO_FORCE'] = '1';

    expect(AgentOutput::enabled())->toBeTrue();
});

it('is enabled when PEST_E2E_AGENT_OUTPUT is set', function (): void {
    $_SERVER['PEST_E2E_AGENT_OUTPUT'] = 'true';

    expect(AgentOutput::enabled())->toBeTrue();
});

it('detects cursor agent from env vars', function (): void {
    $_SERVER['CURSOR_AGENT'] = '1';

    expect(AgentOutput::enabled())->toBeTrue();
});

it('silences collision printer env vars for agent output', function (): void {
    $_SERVER['COLLISION_PRINTER'] = 'DefaultPrinter';
    $_SERVER['COLLISION_PRINTER_COMPACT'] = 'true';
    $_ENV['COLLISION_PRINTER'] = 'DefaultPrinter';

    AgentOutput::silenceTestRunnerOutput();

    expect($_SERVER)->not->toHaveKey('COLLISION_PRINTER')
        ->and($_ENV)->not->toHaveKey('COLLISION_PRINTER')
        ->and($_SERVER['PEST_PARALLEL_NO_OUTPUT'] ?? null)->toBe('1');
});
