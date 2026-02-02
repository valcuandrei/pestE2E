<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\ProjectConfigDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;

function makeReport(array $override = []): array
{
    return array_replace_recursive([
        'schema' => 'pest-e2e.v1',
        'project' => 'frontend',
        'runId' => 'run-123',
        'stats' => ['passed' => 1, 'failed' => 0, 'skipped' => 0, 'durationMs' => 10],
        'tests' => [['name' => 'ok', 'status' => 'passed']],
    ], $override);
}

it('reads and validates report for run', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pest-e2e-');
    file_put_contents($tmp, json_encode(makeReport(), JSON_THROW_ON_ERROR));

    $project = new ProjectConfigDTO(
        name: 'frontend',
        dir: 'js',
        runner: 'Playwright',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: $tmp,
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($project, 'run-123');

    $reader = new JsonReportReader;
    $report = $reader->readForRun($ctx);

    expect($report->project)->toBe('frontend')
        ->and($report->runId)->toBe('run-123');

    @unlink($tmp);
});

it('throws when report project mismatches', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pest-e2e-');
    file_put_contents($tmp, json_encode(makeReport(['project' => 'other']), JSON_THROW_ON_ERROR));

    $project = new ProjectConfigDTO(
        name: 'frontend',
        dir: 'js',
        runner: 'Playwright',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: $tmp,
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($project, 'run-123');

    $reader = new JsonReportReader;

    expect(fn () => $reader->readForRun($ctx))
        ->toThrow(JsonReportParserException::class, 'project mismatch');

    @unlink($tmp);
});

it('throws when report runId mismatches', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pest-e2e-');
    file_put_contents($tmp, json_encode(makeReport(['runId' => 'old']), JSON_THROW_ON_ERROR));

    $project = new ProjectConfigDTO(
        name: 'frontend',
        dir: 'js',
        runner: 'Playwright',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: $tmp,
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($project, 'run-123');

    $reader = new JsonReportReader;

    expect(fn () => $reader->readForRun($ctx))
        ->toThrow(JsonReportParserException::class, 'runId mismatch');

    @unlink($tmp);
});
