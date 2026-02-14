<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Parsers\JsonReportParser;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('runs a target command and ingests the json report', function () {
    $runId = 'run-123';
    $targetName = 'frontend';
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

    $target = new TargetConfigDTO(
        name: $targetName,
        dir: getcwd(),
        command: $command,
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($runId));

    $registry = app(TargetRegistry::class);
    $registry->put($target);

    $runner = app(E2ERunner::class);

    try {
        $runner->run($targetName);

        expect(filesize($reportPath))->toBeGreaterThan(0);

        $data = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        expect($data['target'])->toBe($targetName)
            ->and($data['runId'])->toBe($runId);
    } finally {
        @unlink($reportPath);
    }
});
