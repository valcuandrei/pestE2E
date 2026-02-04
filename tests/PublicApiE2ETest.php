<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\E2E;
use ValcuAndrei\PestE2E\Parsers\JsonReportParser;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('runs via the public e2e helper using the shared composition root', function () {
    E2E::reset();
    E2E::instance()->useRunIdGenerator(new FixedRunIdGenerator('run-123'));

    $targetName = 'frontend';
    $runId = 'run-123';
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
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

    expect($reportPath)->not->toBeFalse();

    try {
        e2e()->target($targetName, fn ($p) => $p
            ->dir(getcwd())
            ->runner('Playwright')
            ->command($command)
            ->report('json', $reportPath)
        );

        e2e($targetName)->run();

        $data = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        expect($data['target'])->toBe($targetName)
            ->and($data['runId'])->toBe($runId);
    } finally {
        @unlink($reportPath);
    }
});
