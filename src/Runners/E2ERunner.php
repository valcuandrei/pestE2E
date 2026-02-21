<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use RuntimeException;
use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportErrorDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\Enums\TestStatusType;
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

        try {
            $report = $this->reportReader->readForRun($context);

            if ($result->exitCode !== 0 && $report->isSuccessful()) {
                $report = $report->withStats($report->stats->withFailed(1));
                $message = $this->formatProcessFailureMessage($result->exitCode, $result->stderr, $result->stdout);

                $synthetic = new JsonReportTestDTO(
                    name: 'E2E process failed',
                    status: TestStatusType::FAILED,
                    file: null,
                    durationMs: null,
                    id: null,
                    error: new JsonReportErrorDTO($message),
                );

                return $report->withTests([...$report->getTests(), $synthetic]);
            }
        } catch (\Throwable $reportException) {
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

        return $report;
    }

    private function formatProcessFailureMessage(int $exitCode, string $stderr, string $stdout): string
    {
        $stderr = trim($stderr);
        $stdout = trim($stdout);
        $body = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : '(no output)');
        $maxChars = 4000;

        if (mb_strlen($body) > $maxChars) {
            $body = mb_substr($body, -$maxChars);
            $body = "[output truncated to last {$maxChars} chars]\n".$body;
        }

        return "E2E command exited with code {$exitCode}.\n\n".$body;
    }
}
