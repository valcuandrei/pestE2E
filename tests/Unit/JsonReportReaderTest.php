<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Parsers\PlaywrightParser;
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
    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($target, 'run-123');
    $stdout = json_encode(makeReport(), JSON_THROW_ON_ERROR);

    $reader = new JsonReportReader(new PlaywrightParser);
    $report = $reader->readForRun($ctx, $stdout);

    expect($report->target)->toBe('frontend')
        ->and($report->runId)->toBe('run-123');
});

it('normalizes report target from run context', function () {
    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($target, 'run-123');
    $stdout = json_encode(makeReport(['target' => 'other']), JSON_THROW_ON_ERROR);

    $reader = new JsonReportReader(new PlaywrightParser);

    $report = $reader->readForRun($ctx, $stdout);

    expect($report->target)->toBe('frontend');
});

it('normalizes report runId from run context', function () {
    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        env: [],
        params: [],
    );

    $ctx = RunContextDTO::make($target, 'run-123');
    $stdout = json_encode(makeReport(['runId' => 'old']), JSON_THROW_ON_ERROR);

    $reader = new JsonReportReader(new PlaywrightParser);

    $report = $reader->readForRun($ctx, $stdout);

    expect($report->runId)->toBe('run-123');
});
