<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;

/**
 * @internal
 */
final class ProcessRunner
{
    /**
     * Run the process.
     */
    public function run(ProcessPlanDTO $plan): ProcessResultDTO
    {
        $start = microtime(true);

        $process = Process::fromShellCommandline(
            command: $plan->command->command,
            cwd: $plan->command->workingDirectory,
            env: $plan->command->getMergedEnv(),
        );

        if ($plan->options->timeoutSeconds !== null) {
            $process->setTimeout($plan->options->timeoutSeconds);
        }

        if ($plan->options->inheritTty && Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run();

        $duration = microtime(true) - $start;

        return new ProcessResultDTO(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            durationSeconds: $duration,
        );
    }
}
