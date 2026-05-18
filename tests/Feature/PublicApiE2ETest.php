<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\E2E as CompositionRoot;
use ValcuAndrei\PestE2E\Parsers\PlaywrightParser;
use ValcuAndrei\PestE2E\PublicApi\E2E;
use ValcuAndrei\PestE2E\PublicApi\E2ETargetHandle;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Runners\E2ERunner;
use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

it('registers targets and resolves target handles via public api', function () {
    $targetName = 'frontend';
    $registry = new TargetRegistry;
    $runIdGenerator = new class implements RunIdGeneratorContract
    {
        public function generate(): string
        {
            return 'run-public-123';
        }
    };
    $worker = new class implements JsWorkerContract
    {
        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            return new ProcessResultDTO(
                exitCode: 0,
                stdout: '{"schema":"pest-e2e.v1","target":"frontend","runId":"run-public-123","stats":{"passed":1,"failed":0,"skipped":0,"durationMs":1},"tests":[{"name":"ok","status":"passed"}]}',
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
        runIdGenerator: $runIdGenerator,
        reportDirectoryManager: new ReportDirectoryManager,
    );
    $authIssuer = new class implements AuthTicketIssuerContract
    {
        public function issueForUser(mixed $user, array $meta = []): string
        {
            return 'ticket-123';
        }
    };
    $root = new CompositionRoot($registry, $runIdGenerator, $authIssuer, $runner);
    app()->instance(CompositionRoot::class, $root);

    $api = new E2E($root, app());
    $api->target($targetName, fn ($p) => $p->dir(getcwd()));
    $handle = $api->targetHandle($targetName);

    expect($registry->get($targetName))->toBeInstanceOf(TargetConfigDTO::class)
        ->and($handle)->toBeInstanceOf(E2ETargetHandle::class);
});
