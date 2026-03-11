<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners\Js;

use ValcuAndrei\PestE2E\Contracts\JsRunnerContract;
use ValcuAndrei\PestE2E\DTO\JsRunnerCapabilitiesDTO;
use ValcuAndrei\PestE2E\DTO\JsRunRequestDTO;
use ValcuAndrei\PestE2E\DTO\JsRunResultDTO;
use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;

final class PlaywrightColdRunner implements JsRunnerContract
{
    private bool $running = false;

    public function __construct(
        private readonly ProcessRunner $processRunner,
    ) {}

    /**
     * Start the JS runner.
     */
    public function start(): void
    {
        $this->running = true;
    }

    /**
     * Check if the JS runner is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Run a JS request.
     */
    public function run(JsRunRequestDTO $request): JsRunResultDTO
    {
        if (! $this->running) {
            $this->start();
        }

        $plan = new ProcessPlanDTO(
            command: new ProcessCommandDTO(
                command: $request->command,
                workingDirectory: $request->workingDirectory,
                env: $request->env,
            ),
            options: new ProcessOptionsDTO(
                timeoutSeconds: $request->timeoutSeconds,
                inheritTty: $request->inheritTty,
            ),
        );

        $result = $this->processRunner->run($plan);

        return new JsRunResultDTO(
            exitCode: $result->exitCode,
            stdout: $result->stdout,
            stderr: $result->stderr,
            durationSeconds: $result->durationSeconds,
        );
    }

    /**
     * Stop the JS runner.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Get the capabilities of the JS runner.
     */
    public function capabilities(): JsRunnerCapabilitiesDTO
    {
        return new JsRunnerCapabilitiesDTO(
            supportsPersistentRuntime: false,
            requiresExplicitStart: false,
        );
    }
}
