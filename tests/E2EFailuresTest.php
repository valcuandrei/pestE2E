<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\Parsers\JsonReportParser;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('throws a readable exception when the json report contains failures', function () {
    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator('run-123'));

    $targetName = 'frontend';
    $runId = 'run-123';
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
            'durationMs' => 5,
        ],
        'tests' => [
            [
                'name' => 'it fails',
                'status' => 'failed',
                'file' => 'tests/FakeTest.ts',
                'error' => ['message' => 'Boom'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $command = 'php -r "file_put_contents('
        .var_export($reportPath, true)
        .', base64_decode('
        .var_export(base64_encode($reportJson), true)
        .'));"';

    try {
        e2e()->target(
            $targetName,
            fn ($p) => $p
                ->dir(getcwd())
                ->runner('Playwright')
                ->command($command)
                ->report('json', $reportPath)
        );

        expect(fn () => e2e($targetName)->run())
            ->toThrow(RuntimeException::class, 'E2E failures')
            ->and(fn () => e2e($targetName)->run())
            ->toThrow(RuntimeException::class, 'it fails');
    } finally {
        @unlink($reportPath);
    }
});
