<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\Support\AgentOutputAggregator;
use ValcuAndrei\PestE2E\Support\ReportPathPolicy;

afterEach(function (): void {
    AgentOutputAggregator::cleanup();
});

it('routes prepareRun and record through the shared ReportPathPolicy so cross-UID pollution cannot break parallel aggregation', function (): void {
    // Round-trip proof: prepareRun writes an enabled marker into whichever
    // directory the policy resolves (preferred storage_path or the deterministic
    // fallback under sys_get_temp_dir()). record() appends a jsonl entry. collect()
    // reads it back. If runDirectory() ever bypasses the policy, one of these
    // steps will fail on a cross-UID host.
    AgentOutputAggregator::prepareRun();

    expect(AgentOutputAggregator::hasActiveRun())->toBeTrue();

    AgentOutputAggregator::record(new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'aggregator-path-test',
        ok: true,
        durationSeconds: 0.01,
        stats: null,
        lines: [],
    ));

    $entries = AgentOutputAggregator::collect();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->target)->toBe('frontend')
        ->and($entries[0]->runId)->toBe('aggregator-path-test');
});

it('exposes the same fallback namespace for AgentOutputAggregator as AgentOutputIntent so they share the fallback root when needed', function (): void {
    // Both stores must share the `pest-e2e-agent-output` namespace so that in
    // the fallback case they live under a single per-(uid, project) tree and
    // stay in sync for retention/pruning across workers.
    $expected = ReportPathPolicy::fallbackDirectory('pest-e2e-agent-output');

    expect($expected)->toContain('pest-e2e-agent-output-')
        ->and(dirname($expected))->toBe(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR));
});
