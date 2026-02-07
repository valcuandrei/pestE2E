<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use RuntimeException;
use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\Readers\JsonReportReader;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;

/**
 * @internal
 */
final readonly class E2ERunner
{
    /**
     * Create a new E2ERunner instance.
     */
    public function __construct(
        private TargetRegistry $registry,
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
    public function run(
        string $targetName,
        array $env = [],
        array $params = [],
        ?ProcessOptionsDTO $options = null,
        ?string $runId = null,
    ): JsonReportDTO {
        $target = $this->registry->get($targetName);
        $runId ??= $this->runIdGenerator->generate();
        $context = RunContextDTO::make($target, $runId, $env, $params);
        $plan = $this->planBuilder->build($context, $options);
        $result = $this->processRunner->run($plan);

        if (! $result->isSuccessful()) {
            throw new RuntimeException(
                "E2E command failed (exit {$result->exitCode}).\n\n".
                    "CMD:\n{$plan->command->command}\n\n".
                    "CWD:\n{$plan->command->workingDirectory}\n\n".
                    "STDOUT:\n{$result->stdout}\n\n".
                    "STDERR:\n{$result->stderr}"
            );
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

            throw new RuntimeException("E2E failures for {$targetName} ({$runId}):\n".implode("\n", $lines));
        }

        return $report;
    }
}
