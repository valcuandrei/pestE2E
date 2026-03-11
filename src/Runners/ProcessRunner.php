<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\Support\TimingProbe;

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
        TimingProbe::mark('js_runner_spawn', [
            'cwd' => $plan->command->workingDirectory,
        ]);

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

            // In TTY mode, output is streamed directly to the terminal by the OS/PTY.
            // Symfony may not reliably capture output buffers in this mode, but that's OK.
            $process->run();

            $duration = microtime(true) - $start;

            return new ProcessResultDTO(
                exitCode: $process->getExitCode() ?? 1,
                stdout: $process->getOutput(),
                stderr: $process->getErrorOutput(),
                durationSeconds: $duration,
            );
        }

        // Stream output live while still capturing it.
        $stdout = '';
        $stderr = '';

        $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr): void {
            if ($type === Process::OUT) {
                $stdout .= $buffer;
                fwrite(STDOUT, $buffer);
                fflush(STDOUT);

                return;
            }

            $stderr .= $buffer;
            fwrite(STDERR, $buffer);
            fflush(STDERR);
        });

        $duration = microtime(true) - $start;

        return new ProcessResultDTO(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $stdout,
            stderr: $stderr,
            durationSeconds: $duration,
        );
    }
}
