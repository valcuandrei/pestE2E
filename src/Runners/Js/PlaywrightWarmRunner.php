<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners\Js;

use RuntimeException;
use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Contracts\JsRunnerContract;
use ValcuAndrei\PestE2E\DTO\JsRunnerCapabilitiesDTO;
use ValcuAndrei\PestE2E\DTO\JsRunRequestDTO;
use ValcuAndrei\PestE2E\DTO\JsRunResultDTO;

final class PlaywrightWarmRunner implements JsRunnerContract
{
    private const STARTUP_TIMEOUT_SECONDS = 10;

    private bool $running = false;

    private ?string $wsEndpoint = null;

    private ?Process $browserServerProcess = null;

    private string $startupStdout = '';

    private string $startupStderr = '';

    private string $workingDirectory = '';

    public function __construct(
        private readonly PlaywrightColdRunner $coldRunner,
    ) {}

    /**
     * Start the warm JS runner lifecycle.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->workingDirectory = $this->workingDirectory !== '' ? $this->workingDirectory : $this->currentWorkingDirectory();
        $this->running = true;
        $this->wsEndpoint = $this->resolveWsEndpoint();

        if ($this->wsEndpoint !== null) {
            return;
        }

        $this->startBrowserServerProcess();
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
        $this->workingDirectory = $request->workingDirectory;

        if (! $this->running) {
            $this->start();
        }

        if (! is_string($this->wsEndpoint) || $this->wsEndpoint === '') {
            throw new RuntimeException('Warm runner did not resolve a Playwright websocket endpoint.');
        }

        $warmRequest = new JsRunRequestDTO(
            command: $request->command,
            workingDirectory: $request->workingDirectory,
            env: array_replace($request->env, [
                'PEST_E2E_WARM_WS_ENDPOINT' => $this->wsEndpoint,
                'PEST_E2E_WARM_MODE' => '1',
            ]),
            timeoutSeconds: $request->timeoutSeconds,
            inheritTty: $request->inheritTty,
        );

        return $this->coldRunner->run($warmRequest);
    }

    /**
     * Stop the warm JS runner lifecycle.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->wsEndpoint = null;
        $this->startupStdout = '';
        $this->startupStderr = '';

        if ($this->browserServerProcess instanceof Process) {
            $this->browserServerProcess->stop(2);
        }

        $this->browserServerProcess = null;
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

    /**
     * Start a persistent Playwright BrowserServer process and capture wsEndpoint.
     */
    private function startBrowserServerProcess(): void
    {
        $this->startupStdout = '';
        $this->startupStderr = '';

        $cwd = $this->workingDirectory !== '' ? $this->workingDirectory : $this->currentWorkingDirectory();
        $process = new Process(
            command: [
                'node',
                '--input-type=module',
                '-e',
                "import { chromium } from '@playwright/test';\n".
                "const server = await chromium.launchServer({ headless: true });\n".
                "console.log(server.wsEndpoint());\n".
                'setInterval(() => {}, 1 << 30);',
            ],
            cwd: $cwd,
            env: $_ENV,
            timeout: null,
        );

        $process->setTimeout(null);
        $process->start(function (string $type, string $buffer): void {
            if ($type === Process::OUT) {
                $this->startupStdout .= $buffer;

                return;
            }

            $this->startupStderr .= $buffer;
        });

        $this->browserServerProcess = $process;
        $this->wsEndpoint = $this->awaitWsEndpoint();
    }

    /**
     * Wait for the BrowserServer websocket endpoint.
     */
    private function awaitWsEndpoint(): string
    {
        $deadline = microtime(true) + self::STARTUP_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $process = $this->browserServerProcess;

            if (! $process instanceof Process) {
                break;
            }

            if (! $process->isRunning()) {
                break;
            }

            $endpoint = $this->extractWsEndpoint($this->startupStdout);

            if ($endpoint !== null) {
                return $endpoint;
            }

            usleep(50_000);
        }

        $stderr = trim($this->startupStderr);
        $stdout = trim($this->startupStdout);

        throw new RuntimeException(
            'Warm runner failed to start BrowserServer within '.self::STARTUP_TIMEOUT_SECONDS."s.\n\n".
            "STDOUT:\n".($stdout !== '' ? $stdout : '(no output)')."\n\n".
            "STDERR:\n".($stderr !== '' ? $stderr : '(no output)')
        );
    }

    /**
     * Extract websocket endpoint from process output.
     */
    private function extractWsEndpoint(string $output): ?string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'ws://') || str_starts_with($line, 'wss://')) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Resolve the current working directory as a string.
     */
    private function currentWorkingDirectory(): string
    {
        $cwd = getcwd();

        return is_string($cwd) ? $cwd : '.';
    }
}
