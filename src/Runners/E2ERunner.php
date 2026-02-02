<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use RuntimeException;
use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\ProjectRegistry;

/**
 * @internal
 */
final readonly class E2ERunner
{
    /**
     * Create a new E2ERunner instance.
     */
    public function __construct(
        private ProjectRegistry $registry,
        private ProcessPlanBuilder $planBuilder,
        private ProcessRunner $processRunner,
        private JsonReportReader $reportReader,
        private RunIdGeneratorContract $runIdGenerator,
    ) {}

    /**
     * Create a new E2ERunner instance.
     *
     * @param  array<string,string>  $env
     * @param  array<string,mixed>  $params
     * @param  ProcessOptionsDTO|null  $options  (optional) process options
     */
    public function run(string $projectName, array $env = [], array $params = [], ?ProcessOptionsDTO $options = null): void
    {
        $project = $this->registry->get($projectName);
        $runId = $this->runIdGenerator->generate();
        $context = RunContextDTO::make($project, $runId, $env, $params);
        $plan = $this->planBuilder->build($context, $options);
        $result = $this->processRunner->run($plan);

        if (! $result->isSuccessful()) {
            throw new RuntimeException(sprintf("E2E command failed (exit %d).\n\nSTDOUT:\n%s\n\nSTDERR:\n%s", $result->exitCode, $result->stdout, $result->stderr));
        }

        $report = $this->reportReader->readForRun($context);

        if ($report->hasFailures()) {
            $lines = [];
            foreach ($report->getFailedTests() as $t) {
                $lines[] = "- {$t->name}".($t->file ? " ({$t->file})" : '');
                if ($t->error?->message) {
                    $lines[] = "  {$t->error->message}";
                }
            }

            throw new RuntimeException(sprintf("E2E failures:\n%s", implode("\n", $lines)));
        }
    }
}
