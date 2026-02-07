<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Parsers\JsonReportParser;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

beforeEach(function () {
    app(E2EOutputStore::class)->flush();
});

it('nests e2e output under the current test name', function () {
    $runId = 'run-nested';
    $targetName = 'frontend';
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $testName = test()->getPrintableTestCaseMethodName();

    expect($reportPath)->not->toBeFalse();

    $reportJson = json_encode([
        'schema' => JsonReportParser::SCHEMA_V1,
        'target' => $targetName,
        'runId' => $runId,
        'stats' => [
            'passed' => 1,
            'failed' => 0,
            'skipped' => 0,
            'durationMs' => 5,
        ],
        'tests' => [
            ['name' => 'ok', 'status' => 'passed'],
        ],
    ], JSON_THROW_ON_ERROR);

    $reportB64 = base64_encode($reportJson);
    $command = 'php -r "file_put_contents('
        .var_export($reportPath, true)
        .', base64_decode('
        .var_export($reportB64, true)
        .'));"';

    $target = new TargetConfigDTO(
        name: $targetName,
        dir: getcwd(),
        runner: 'Playwright',
        command: $command,
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($runId));
    app(TargetRegistry::class)->put($target);

    try {
        e2e($targetName)->run();

        $entries = app(E2EOutputStore::class)->all();
        $lines = $entries[0]->lines;

        expect($entries)->toHaveCount(1)
            ->and($lines[0])->toBe($testName)
            ->and($lines[1])->toContain('  └─ E2E › '.$targetName.' (runId '.$runId.')');
    } finally {
        @unlink($reportPath);
    }
});

it('stores a passed run summary when the target succeeds', function () {
    $runId = 'run-123';
    $targetName = 'frontend';
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');

    expect($reportPath)->not->toBeFalse();

    $reportJson = json_encode([
        'schema' => JsonReportParser::SCHEMA_V1,
        'target' => $targetName,
        'runId' => $runId,
        'stats' => [
            'passed' => 1,
            'failed' => 0,
            'skipped' => 0,
            'durationMs' => 5,
        ],
        'tests' => [
            ['name' => 'ok', 'status' => 'passed'],
        ],
    ], JSON_THROW_ON_ERROR);

    $reportB64 = base64_encode($reportJson);
    $command = 'php -r "file_put_contents('
        .var_export($reportPath, true)
        .', base64_decode('
        .var_export($reportB64, true)
        .'));"';

    $target = new TargetConfigDTO(
        name: $targetName,
        dir: getcwd(),
        runner: 'Playwright',
        command: $command,
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($runId));
    app(TargetRegistry::class)->put($target);

    try {
        e2e($targetName)->run();

        $entries = app(E2EOutputStore::class)->all();
        $text = implode("\n", $entries[0]->lines);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->ok)->toBeTrue()
            ->and($entries[0]->runId)->toBe($runId)
            ->and($text)->toContain('PASSED')
            ->and($text)->toContain($targetName)
            ->and($text)->toContain($runId);
    } finally {
        @unlink($reportPath);
    }
});

it('stores a failed run summary and rethrows on failures', function () {
    $runId = 'run-456';
    $targetName = 'frontend';
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');

    expect($reportPath)->not->toBeFalse();

    $reportJson = json_encode([
        'schema' => JsonReportParser::SCHEMA_V1,
        'target' => $targetName,
        'runId' => $runId,
        'stats' => [
            'passed' => 0,
            'failed' => 1,
            'skipped' => 0,
            'durationMs' => 8,
        ],
        'tests' => [
            [
                'name' => 'bad',
                'status' => 'failed',
                'error' => ['message' => 'oops'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $reportB64 = base64_encode($reportJson);
    $command = 'php -r "file_put_contents('
        .var_export($reportPath, true)
        .', base64_decode('
        .var_export($reportB64, true)
        .'));"';

    $target = new TargetConfigDTO(
        name: $targetName,
        dir: getcwd(),
        runner: 'Playwright',
        command: $command,
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($runId));
    app(TargetRegistry::class)->put($target);

    try {
        expect(fn () => e2e($targetName)->run())->toThrow(\RuntimeException::class);

        $entries = app(E2EOutputStore::class)->all();
        $text = implode("\n", $entries[0]->lines);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->ok)->toBeFalse()
            ->and($entries[0]->runId)->toBe($runId)
            ->and($text)->toContain('FAILED')
            ->and($text)->toContain($targetName)
            ->and($text)->toContain($runId);
    } finally {
        @unlink($reportPath);
    }
});
