<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners\Js;

use ValcuAndrei\PestE2E\Contracts\JsRunnerContract;
use ValcuAndrei\PestE2E\DTO\JsRunnerCapabilitiesDTO;
use ValcuAndrei\PestE2E\DTO\JsRunRequestDTO;
use ValcuAndrei\PestE2E\DTO\JsRunResultDTO;

final class PlaywrightWarmRunner implements JsRunnerContract
{
    private bool $running = false;

    private ?string $wsEndpoint = null;

    public function __construct(
        private readonly PlaywrightColdRunner $coldRunner,
    ) {}

    /**
     * Start the warm JS runner lifecycle.
     */
    public function start(): void
    {
        $this->running = true;
        $this->wsEndpoint ??= $this->resolveWsEndpoint();
    }

    /**
     * Check if the warm JS runner is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Run a JS request through the warm runner.
     */
    public function run(JsRunRequestDTO $request): JsRunResultDTO
    {
        if (! $this->running) {
            $this->start();
        }

        return $this->coldRunner->run($request);
    }

    /**
     * Stop the warm JS runner lifecycle.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->wsEndpoint = null;
        $this->coldRunner->stop();
    }

    /**
     * Get the capabilities of the warm runner.
     */
    public function capabilities(): JsRunnerCapabilitiesDTO
    {
        return new JsRunnerCapabilitiesDTO(
            supportsPersistentRuntime: true,
            requiresExplicitStart: true,
        );
    }

    /**
     * Get the current warm runner websocket endpoint if available.
     */
    public function wsEndpoint(): ?string
    {
        return $this->wsEndpoint;
    }

    /**
     * Resolve the websocket endpoint for the warm runner.
     */
    private function resolveWsEndpoint(): ?string
    {
        $value = getenv('PEST_E2E_WARM_WS_ENDPOINT');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
