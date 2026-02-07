<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\JsonReportErrorDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\Enums\TestStatusType;
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

it('formats nested output with js test lines and errors', function () {
    $formatter = new E2EOutputFormatter;
    $stats = new JsonReportStatsDTO(
        passed: 1,
        failed: 1,
        skipped: 1,
        durationMs: 15,
    );

    $tests = [
        new JsonReportTestDTO(
            name: 'ok',
            status: TestStatusType::PASSED,
        ),
        new JsonReportTestDTO(
            name: 'bad',
            status: TestStatusType::FAILED,
            error: new JsonReportErrorDTO("Boom\nDetails"),
        ),
        new JsonReportTestDTO(
            name: 'skip',
            status: TestStatusType::SKIPPED,
        ),
    ];

    $lines = $formatter->buildRunLines(
        target: 'frontend',
        runId: 'run-nested',
        ok: false,
        durationSeconds: 0.02,
        stats: $stats,
        tests: $tests,
        parentTestName: 'Parent Test',
        extraLines: ['Extra failure'],
    );

    expect($lines[0])->toBe('Parent Test')
        ->and($lines[1])->toContain('└─ E2E › frontend (runId run-nested)')
        ->and($lines)->toContain('     ✓ ok')
        ->and($lines)->toContain('     ✗ bad')
        ->and($lines)->toContain('     - skip')
        ->and($lines)->toContain('       Boom')
        ->and($lines)->toContain('       Details')
        ->and($lines)->toContain('     Extra failure')
        ->and(implode("\n", $lines))->toContain('passed=1 failed=1 skipped=1')
        ->and(implode("\n", $lines))->toContain('duration=15ms');
});
