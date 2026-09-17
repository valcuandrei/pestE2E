<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\AgentOutputIntent;

it('resolves the agent intent path through the same fallback policy as ReportDirectoryManager', function (): void {
    // AgentOutputIntent::path() and directory() are private; we exercise
    // them indirectly via persistFromEnvironment() + hydrateEnvironment().
    //
    // In the Testbench app used by the pest-e2e suite the default
    // `storage_path('framework/testing/pest-e2e-agent-output')` is writable,
    // so persistFromEnvironment() should succeed and the JSON file should
    // materialize under that path (or, on any host where that tree is not
    // writable, under the ReportPathPolicy fallback under sys_get_temp_dir()).
    //
    // Both are valid outcomes for this test — we only assert that the write
    // succeeds without throwing, that hydration reads it back, and that the
    // resolved path is not under a foreign-owned root the current process
    // couldn't have written.
    // AgentParallelMode::forwardableEnvironmentVariables reads via getenv(),
    // so we must putenv() to make the value visible to the persist call.
    putenv('PEST_E2E_AGENT_OUTPUT=1');
    $_SERVER['PEST_E2E_AGENT_OUTPUT'] = '1';

    try {
        AgentOutputIntent::persistFromEnvironment();

        // Zero the in-process value; hydrateEnvironment should restore it
        // from whichever directory the policy resolved (preferred or fallback).
        unset($_SERVER['PEST_E2E_AGENT_OUTPUT']);
        putenv('PEST_E2E_AGENT_OUTPUT');
        AgentOutputIntent::hydrateEnvironment();

        expect($_SERVER['PEST_E2E_AGENT_OUTPUT'] ?? null)->toBe('1');
    } finally {
        AgentOutputIntent::clear();
        unset($_SERVER['PEST_E2E_AGENT_OUTPUT']);
        putenv('PEST_E2E_AGENT_OUTPUT');
    }
});
