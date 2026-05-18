<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\Support\AgentOutputSummary;

it('builds a pao-compatible json summary from an entry', function (): void {
    $entry = new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-123',
        ok: false,
        durationSeconds: 1.163,
        stats: new JsonReportStatsDTO(passed: 3, failed: 1, skipped: 0, durationMs: 1163),
        lines: [],
        reportDirectory: '/tmp/pest-e2e/reports/frontend/run-123',
    );

    $summary = AgentOutputSummary::fromEntry($entry);

    expect($summary)->toBe([
        'target' => 'frontend',
        'result' => 'failed',
        'passed' => 3,
        'failed' => 1,
        'duration_ms' => 1163,
        'report_dir' => '/tmp/pest-e2e/reports/frontend/run-123',
    ])
        ->and(AgentOutputSummary::encode($entry))->toBe(json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
});

it('includes php and js failure details in agent json when a run fails', function (): void {
    $entry = new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-fail',
        ok: false,
        durationSeconds: 30.056,
        stats: new JsonReportStatsDTO(passed: 0, failed: 1, skipped: 0, durationMs: 30056),
        lines: [],
        reportDirectory: '/tmp/pest-e2e/reports/frontend/run-fail',
        phpTestFile: 'tests/Browser/UserProfileTest.php',
        phpTestName: 'that an authenticated user can update their profile',
        failures: [
            [
                'name' => 'UserProfile can update their profile',
                'js_file' => 'resources/js/e2e/userProfile.spec.ts',
                'message' => 'locator.fill: Timeout 30000ms exceeded.',
                'stack' => 'at userProfile.spec.ts:17:32',
            ],
        ],
    );

    $summary = AgentOutputSummary::fromEntry($entry);

    expect($summary)->toMatchArray([
        'target' => 'frontend',
        'result' => 'failed',
        'passed' => 0,
        'failed' => 1,
        'duration_ms' => 30056,
        'report_dir' => '/tmp/pest-e2e/reports/frontend/run-fail',
        'php_test' => [
            'file' => 'tests/Browser/UserProfileTest.php',
            'name' => 'that an authenticated user can update their profile',
        ],
        'failures' => [
            [
                'name' => 'UserProfile can update their profile',
                'js_file' => 'resources/js/e2e/userProfile.spec.ts',
                'message' => 'locator.fill: Timeout 30000ms exceeded.',
                'stack' => 'at userProfile.spec.ts:17:32',
            ],
        ],
    ]);
});

it('includes a top-level php error when no playwright failures were parsed', function (): void {
    $entry = new E2EOutputEntryDTO(
        type: 'run',
        target: 'frontend',
        runId: 'run-php-fail',
        ok: false,
        durationSeconds: 0.5,
        stats: null,
        lines: [],
        reportDirectory: null,
        phpTestFile: 'tests/Browser/DashboardTest.php',
        phpTestName: 'dashboard test',
        errorMessage: 'Server failed to start',
        errorStack: '#0 /var/www/html/tests/Browser/DashboardTest.php:12',
    );

    $summary = AgentOutputSummary::fromEntry($entry);

    expect($summary)->toHaveKey('error')
        ->and($summary['error'])->toBe([
            'message' => 'Server failed to start',
            'stack' => '#0 /var/www/html/tests/Browser/DashboardTest.php:12',
        ]);
});
