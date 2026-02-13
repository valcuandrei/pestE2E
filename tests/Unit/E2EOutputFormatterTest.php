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

    $branchPrefix = E2EOutputFormatter::BASE_INDENT.E2EOutputFormatter::BRANCH_PREFIX;
    $childPrefix = E2EOutputFormatter::BASE_INDENT.E2EOutputFormatter::CHILD_INDENT;
    $errorPrefix = $childPrefix.E2EOutputFormatter::ERROR_INDENT;
    $plainLines = array_map(static fn (string $line): string => normalizeFormattedLine($line), $lines);

    expect($lines[0])->toBe('Parent Test')
        ->and($lines[1])->toContain($branchPrefix.'E2E › frontend (runId run-nested)')
        ->and($plainLines)->toContain($childPrefix.'✓ ok')
        ->and($plainLines)->toContain($childPrefix.'✗ bad')
        ->and($plainLines)->toContain($childPrefix.'- skip')
        ->and($plainLines)->toContain($errorPrefix.'Boom')
        ->and($plainLines)->toContain($errorPrefix.'Details')
        ->and($plainLines)->toContain($childPrefix.'Extra failure')
        ->and(normalizeFormattedLine(implode("\n", $lines)))->toContain('passed=1 failed=1 skipped=1')
        ->and(normalizeFormattedLine(implode("\n", $lines)))->toContain('duration=15ms');
});

function normalizeFormattedLine(string $line): string
{
    $withoutTags = strip_tags($line);

    return (string) preg_replace('/\e\[[0-9;]*m/', '', $withoutTags);
}
