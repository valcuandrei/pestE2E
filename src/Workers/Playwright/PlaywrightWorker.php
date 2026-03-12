<?php

namespace ValcuAndrei\PestE2E\Workers\Playwright;

use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use Symfony\Component\Process\Process;

class PlaywrightWorker implements JsWorkerContract
{
    /**
     * Run the Playwright worker.
     */
    public function run(ProcessPlanDTO $plan): ProcessResultDTO
    {
        $start = microtime(true);

        $process = new Process(
            command: [
                'npx',
                'playwright',
                'test',
                '--grep',
                $plan->command->getMergedEnv()['PEST_E2E_TEST_FILTER'],
                '--reporter',
                'json',
            ],
            cwd: $plan->command->workingDirectory,
            env: $plan->command->getMergedEnv(),
        );

        if ($plan->options->timeoutSeconds !== null) {
            $process->setTimeout($plan->options->timeoutSeconds);
        }

        $process->mustRun();

        $duration = microtime(true) - $start;

        return new ProcessResultDTO(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            durationSeconds: $duration,
        );
    }
}
