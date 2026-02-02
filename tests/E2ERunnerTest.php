<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\DTO\ProjectConfigDTO;
use ValcuAndrei\PestE2E\Parsers\JsonReportParser;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\ProjectRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('runs a project command and ingests the json report', function () {
    $registry = new ProjectRegistry;
    $runId = 'run-123';
    $projectName = 'frontend';
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $reportJson = json_encode([
        'schema' => JsonReportParser::SCHEMA_V1,
        'project' => $projectName,
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

    $project = new ProjectConfigDTO(
        name: $projectName,
        dir: getcwd(),
        runner: 'Playwright',
        command: $command,
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
    );

    $registry->put($project);

    $runner = new E2ERunner(
        registry: $registry,
        planBuilder: new ProcessPlanBuilder(new TempParamsFileWriter),
        processRunner: new ProcessRunner,
        reportReader: new JsonReportReader,
        runIdGenerator: new FixedRunIdGenerator($runId),
    );

    try {
        $runner->run($projectName);

        expect(filesize($reportPath))->toBeGreaterThan(0);

        $data = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        expect($data['project'])->toBe($projectName)
            ->and($data['runId'])->toBe($runId);
    } finally {
        @unlink($reportPath);
    }
});
