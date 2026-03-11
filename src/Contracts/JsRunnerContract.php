<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

use ValcuAndrei\PestE2E\DTO\JsRunnerCapabilitiesDTO;
use ValcuAndrei\PestE2E\DTO\JsRunRequestDTO;
use ValcuAndrei\PestE2E\DTO\JsRunResultDTO;

interface JsRunnerContract
{
    /**
     * Start the JS runner.
     */
    public function start(): void;

    /**
     * Check if the JS runner is running.
     */
    public function isRunning(): bool;

    /**
     * Run a JS request.
     */
    public function run(JsRunRequestDTO $request): JsRunResultDTO;

    /**
     * Stop the JS runner.
     */
    public function stop(): void;

    /**
     * Get the capabilities of the JS runner.
     */
    public function capabilities(): JsRunnerCapabilitiesDTO;
}
