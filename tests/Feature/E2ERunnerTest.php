<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Parsers\PlaywrightParser;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

it('runs a target command and ingests the json report', function () {
    $runId = 'run-123';
    $targetName = 'frontend';
    $reportJson = json_encode([
        'schema' => JsonReportDTO::SCHEMA_V1,
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

    $target = new TargetConfigDTO(
        name: $targetName,
        dir: getcwd(),
        env: [],
        params: [],
    );

    $registry = new TargetRegistry;
    $registry->put($target);
    $planBuilder = new ProcessPlanBuilder(new TempParamsFileWriter);
    $reportReader = new JsonReportReader(new PlaywrightParser);
    $runIdGenerator = new class($runId) implements RunIdGeneratorContract
    {
        public function __construct(private readonly string $runId) {}

        public function generate(): string
        {
            return $this->runId;
        }
    };
    $worker = new class($reportJson) implements JsWorkerContract
    {
        public function __construct(private readonly string $reportJson) {}

        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            return new ProcessResultDTO(
                exitCode: 0,
                stdout: $this->reportJson,
                stderr: '',
                durationSeconds: 0.01,
            );
        }
    };
    $runner = new E2ERunner(
        registry: $registry,
        planBuilder: $planBuilder,
        jsWorker: $worker,
        reportReader: $reportReader,
        runIdGenerator: $runIdGenerator,
    );

    $report = $runner->run($targetName);

    expect($report->target)->toBe($targetName)
        ->and($report->runId)->toBe($runId);
});
