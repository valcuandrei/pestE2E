<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;

function makeReport(array $override = []): array
{
    return array_replace_recursive([
        'schema' => 'pest-e2e.v1',
        'target' => 'frontend',
        'runId' => 'run-123',
        'stats' => ['passed' => 1, 'failed' => 0, 'skipped' => 0, 'durationMs' => 10],
        'tests' => [['name' => 'ok', 'status' => 'passed']],
    ], $override);
}

it('reads and validates report for run', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pest-e2e-');
    file_put_contents($tmp, json_encode(makeReport(), JSON_THROW_ON_ERROR));

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: $tmp,
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($target, 'run-123');

    $reader = app(JsonReportReader::class);
    $report = $reader->readForRun($ctx);

    expect($report->target)->toBe('frontend')
        ->and($report->runId)->toBe('run-123');

    @unlink($tmp);
});

it('throws when report target mismatches', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pest-e2e-');
    file_put_contents($tmp, json_encode(makeReport(['target' => 'other']), JSON_THROW_ON_ERROR));

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: $tmp,
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($target, 'run-123');

    $reader = app(JsonReportReader::class);

    expect(fn () => $reader->readForRun($ctx))
        ->toThrow(JsonReportParserException::class, 'target mismatch');

    @unlink($tmp);
});

it('throws when report runId mismatches', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pest-e2e-');
    file_put_contents($tmp, json_encode(makeReport(['runId' => 'old']), JSON_THROW_ON_ERROR));

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: $tmp,
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($target, 'run-123');

    $reader = app(JsonReportReader::class);

    expect(fn () => $reader->readForRun($ctx))
        ->toThrow(JsonReportParserException::class, 'runId mismatch');

    @unlink($tmp);
});
