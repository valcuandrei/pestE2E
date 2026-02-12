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
     * Run the E2E test suite for a target.
     *
     * @param  array<string,string>  $env
     * @param  array<string,mixed>  $params
     * @param  ProcessOptionsDTO|null  $options  (optional) process options
     * @param  string|null  $testFilter  (optional) test filter
     */
    public function run(
        string $targetName,
        array $env = [],
        array $params = [],
        ?ProcessOptionsDTO $options = null,
        ?string $runId = null,
        ?string $testFilter = null,
    ): JsonReportDTO {
        $target = $this->registry->get($targetName);
        $runId ??= $this->runIdGenerator->generate();
        $context = RunContextDTO::make($target, $runId, $env, $params, $testFilter);
        $plan = $this->planBuilder->build($context, $options);
        $result = $this->processRunner->run($plan);

        // Always try to read the JSON report first, even on non-zero exit.
        // The run.mjs script always exits 0 and writes the canonical report
        // so the PHP side can read test-level pass/fail details.
        // Only fall back to a raw RuntimeException if the report is unreadable.
        try {
            return $this->reportReader->readForRun($context);
        } catch (\Throwable $reportException) {
            // Report could not be read – fall back to a raw error with process output
            throw new RuntimeException("E2E command failed (exit {$result->exitCode}).\n\n".
                "TARGET:\n{$target->name}\n\n".
                (in_array($testFilter, [null, '', '0'], true) ? '' : "FILTER:\n{$testFilter}\n\n").
                "RUN_ID:\n{$runId}\n\n".
                "CMD:\n{$plan->command->command}\n\n".
                "CWD:\n{$plan->command->workingDirectory}\n\n".
                "STDOUT:\n{$result->stdout}\n\n".
                "STDERR:\n{$result->stderr}\n\n".
                "REPORT ERROR:\n{$reportException->getMessage()}", $reportException->getCode(), $reportException);
        }
    }
}
