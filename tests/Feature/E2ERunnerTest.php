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
use ValcuAndrei\PestE2E\Support\AdmissionController;
use ValcuAndrei\PestE2E\Support\FakeResourceSampler;
use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

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
        reportDirectoryManager: new ReportDirectoryManager,
    );

    $report = $runner->run($targetName);

    expect($report->target)->toBe($targetName)
        ->and($report->runId)->toBe($runId);
});

it('resolves late params only after the run has been admitted', function () {
    $lockDir = sys_get_temp_dir().'/pest-e2e-late-params-tests-'.uniqid();
    mkdir($lockDir, 0700, true);

    $timeline = [];
    $now = 1000.0;

    $registry = new TargetRegistry;
    $registry->put(new TargetConfigDTO(name: 'frontend', dir: getcwd(), env: [], params: []));

    $worker = new class($timeline) implements JsWorkerContract
    {
        /** @param  array<int, string>  $timeline */
        public function __construct(private array &$timeline) {}

        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            $this->timeline[] = 'launched with '.json_encode($plan->params?->params);

            return new ProcessResultDTO(
                exitCode: 0,
                stdout: JsonReportDTO::fakeWithPassedTest()->withTarget('frontend')->withRunId('run-123')->toJson(),
                stderr: '',
                durationSeconds: 0.01,
            );
        }
    };

    $runner = new E2ERunner(
        registry: $registry,
        planBuilder: new ProcessPlanBuilder(new TempParamsFileWriter),
        jsWorker: $worker,
        reportReader: new JsonReportReader(new PlaywrightParser),
        runIdGenerator: new FixedRunIdGenerator('run-123'),
        reportDirectoryManager: new ReportDirectoryManager,
        admissionController: new AdmissionController(
            sampler: (new FakeResourceSampler)->enqueue(95.0, 10.0)->enqueue(10.0, 10.0),
            cpuHighPct: 80.0,
            cpuLowPct: 75.0,
            memoryHighPct: 85.0,
            memoryLowPct: 80.0,
            cooldownMs: 0,
            backoffMs: 1000,
            jitterMs: 0,
            maxWaitSeconds: 60,
            lockPath: $lockDir.'/admission.lock',
            sleeper: function (float $seconds) use (&$timeline, &$now): void {
                $timeline[] = 'queued';
                $now += $seconds;
            },
            clock: function () use (&$now): float {
                return $now;
            },
        ),
    );

    try {
        $runner->run(
            targetName: 'frontend',
            params: ['auth' => ['mode' => 'session'], 'extra' => 'yes'],
            lateParams: function () use (&$timeline): array {
                $timeline[] = 'late params resolved';

                return ['auth' => ['ticket' => 'fresh']];
            },
        );
    } finally {
        @unlink($lockDir.'/admission.lock');
        @unlink($lockDir.'/admission.lock.state');
        @rmdir($lockDir);
    }

    expect($timeline)->toBe([
        'queued',
        'late params resolved',
        'launched with {"auth":{"mode":"session","ticket":"fresh"},"extra":"yes"}',
    ]);
});
