<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;

it('keeps flat output when no parent test name is provided', function () {
    $formatter = new E2EOutputFormatter;
    $stats = new JsonReportStatsDTO(
        passed: 1,
        failed: 0,
        skipped: 0,
        durationMs: 5,
    );

    $lines = $formatter->buildRunLines(
        target: 'frontend',
        runId: 'run-flat',
        ok: true,
        durationSeconds: 0.005,
        stats: $stats,
        tests: [],
        parentTestName: null,
        extraLines: [],
    );

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toStartWith('PestE2E: target "frontend" runId "run-flat"');
});
