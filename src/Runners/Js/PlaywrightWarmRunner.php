<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners\Js;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
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

    private bool $fallbackToCold = false;

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
        $this->fallbackToCold = false;
        $this->wsEndpoint = $this->resolveWsEndpoint();

        if ($this->wsEndpoint !== null && $this->isValidWsEndpoint($this->wsEndpoint)) {
            return;
        }

        if ($this->wsEndpoint !== null && ! $this->isValidWsEndpoint($this->wsEndpoint)) {
            $this->activateColdFallback('Invalid warm wsEndpoint was provided; using cold runner.');

            return;
        }

        try {
            $this->startBrowserServerProcess();
        } catch (Throwable $exception) {
            $this->activateColdFallback(
                'Warm runner startup failed; falling back to cold runner. '.$exception->getMessage()
            );
        }
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

        if ($this->fallbackToCold) {
            return $this->runCold($request);
        }

        if (! is_string($this->wsEndpoint) || ! $this->isValidWsEndpoint($this->wsEndpoint)) {
            $this->activateColdFallback('Warm runner wsEndpoint is invalid; using cold runner.');

            return $this->runCold($request);
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

        try {
            $result = $this->coldRunner->run($warmRequest);

            if ($result->isSuccessful()) {
                return $result;
            }

            if (! $this->looksLikeWarmFailure($result->stderr, $result->stdout)) {
                return $result;
            }

            $this->activateColdFallback('Warm runner execution failed; retrying once with cold runner.');

            return $this->runCold($request);
        } catch (Throwable $exception) {
            $this->activateColdFallback(
                'Warm runner threw during execution; retrying once with cold runner. '.$exception->getMessage()
            );

            return $this->runCold($request);
        }
    }

    /**
     * Stop the warm JS runner lifecycle.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->fallbackToCold = false;
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

    /**
     * Check if a websocket endpoint is valid for Playwright connect.
     */
    private function isValidWsEndpoint(string $endpoint): bool
    {
        return str_starts_with($endpoint, 'ws://') || str_starts_with($endpoint, 'wss://');
    }

    /**
     * Run the request with cold runner semantics.
     */
    private function runCold(JsRunRequestDTO $request): JsRunResultDTO
    {
        return $this->coldRunner->run(new JsRunRequestDTO(
            command: $request->command,
            workingDirectory: $request->workingDirectory,
            env: $this->withoutWarmEnv($request->env),
            timeoutSeconds: $request->timeoutSeconds,
            inheritTty: $request->inheritTty,
        ));
    }

    /**
     * Remove warm-mode env variables for cold fallback.
     *
     * @param  array<string, string|null>  $env
     * @return array<string, string|null>
     */
    private function withoutWarmEnv(array $env): array
    {
        unset($env['PEST_E2E_WARM_WS_ENDPOINT'], $env['PEST_E2E_WARM_MODE']);

        return $env;
    }

    /**
     * Detect warm-run failures that should trigger fallback.
     */
    private function looksLikeWarmFailure(string $stderr, string $stdout): bool
    {
        $haystack = strtolower($stderr."\n".$stdout);
        $signals = [
            'wsendpoint',
            'browsertype.connect',
            'websocket',
            'econnrefused',
            'target closed',
            'browser has been closed',
            'failed to connect',
        ];

        foreach ($signals as $signal) {
            if (str_contains($haystack, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Activate cold fallback mode and emit a warning.
     */
    private function activateColdFallback(string $message): void
    {
        $this->fallbackToCold = true;

        if ($this->browserServerProcess instanceof Process) {
            $this->browserServerProcess->stop(2);
            $this->browserServerProcess = null;
        }

        $this->wsEndpoint = null;
        $this->warn($message);
    }

    /**
     * Emit a warning message in environments with or without Laravel.
     */
    private function warn(string $message): void
    {
        if (function_exists('logger')) {
            logger()->warning($message);

            return;
        }

        fwrite(STDERR, "[pest-e2e] {$message}\n");
    }
}
