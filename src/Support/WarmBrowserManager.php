<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Owns the lifecycle of the per-worker persistent Playwright browser server.
 *
 * Design (per Andy's brief for F5)
 * --------------------------------
 * - one launch-server process per ParaTest worker (keyed by TEST_TOKEN;
 *   solo runs use the sentinel 'solo');
 * - first `endpoint()` call on a worker starts that worker's server and
 *   caches the wsEndpoint printed on its stdout handshake;
 * - subsequent `endpoint()` calls return the cached endpoint without
 *   respawning;
 * - each individual Playwright child then `chromium.connect(wsEndpoint)`s
 *   and receives an isolated browser context/page (Playwright's default
 *   `use.storageState` per test still applies — no cross-test cookie or
 *   storage leakage because contexts don't share state);
 * - the browser server is terminated via `Process::stop()` from a
 *   `TestSuite\Finished` subscriber and from a PHP shutdown handler, so no
 *   orphan chromium processes survive the run;
 * - workers are strictly independent: worker N's endpoint is never handed
 *   to worker M.
 *
 * The Symfony Process is created with `disableOutput: false` so stdout is
 * readable during the handshake, then stdout is drained continuously to
 * keep the child's write buffer from filling and blocking browser I/O.
 *
 * @internal
 */
final class WarmBrowserManager
{
    /** @var array<string, self> */
    private static array $instances = [];

    private ?Process $process = null;

    private ?string $wsEndpoint = null;

    private bool $shutdownHandlerRegistered = false;

    public function __construct(
        private readonly string $workerKey,
        private readonly string $launchScriptPath,
        private readonly ?string $workingDirectory = null,
        private readonly int $startupTimeoutSeconds = 30,
    ) {}

    /**
     * Get (or create) the manager for the current ParaTest worker.
     */
    public static function forCurrentWorker(?string $launchScriptPath = null, ?string $workingDirectory = null, int $startupTimeoutSeconds = 30): self
    {
        $key = ParallelWorkerContext::token() ?? 'solo';

        self::$instances[$key] ??= new self(
            workerKey: $key,
            launchScriptPath: $launchScriptPath ?? self::defaultLaunchScriptPath(),
            workingDirectory: $workingDirectory,
            startupTimeoutSeconds: $startupTimeoutSeconds,
        );

        return self::$instances[$key];
    }

    /**
     * Return the wsEndpoint of this worker's warm browser, launching the
     * server on first call. Blocking: waits up to `startupTimeoutSeconds`
     * for the handshake line on stdout.
     *
     * @throws RuntimeException on launch failure or handshake timeout.
     */
    public function endpoint(): string
    {
        if ($this->wsEndpoint !== null && $this->process instanceof Process && $this->process->isRunning()) {
            return $this->wsEndpoint;
        }

        $this->launch();

        if ($this->wsEndpoint === null) {
            throw new RuntimeException('pest-e2e warm browser did not produce a wsEndpoint handshake.');
        }

        return $this->wsEndpoint;
    }

    public function isRunning(): bool
    {
        return $this->process instanceof Process && $this->process->isRunning();
    }

    public function workerKey(): string
    {
        return $this->workerKey;
    }

    public function pid(): ?int
    {
        if ($this->process instanceof Process && $this->process->isRunning()) {
            return $this->process->getPid();
        }

        return null;
    }

    /**
     * Terminate this worker's browser server. Idempotent.
     */
    public function shutdown(): void
    {
        if ($this->process instanceof Process && $this->process->isRunning()) {
            $this->process->stop(3);
        }

        $this->process = null;
        $this->wsEndpoint = null;
    }

    /**
     * Terminate every warm browser server this PHP process has started.
     * Called from TestSuite\Finished and from the shutdown handler.
     */
    public static function stopAll(): void
    {
        foreach (self::$instances as $manager) {
            $manager->shutdown();
        }
    }

    private function launch(): void
    {
        if (! is_file($this->launchScriptPath)) {
            throw new RuntimeException("pest-e2e warm-browser launch script not found: {$this->launchScriptPath}");
        }

        $env = ProcessEnvironment::normalize(array_merge($_ENV, [
            'TEST_TOKEN' => $this->workerKey === 'solo' ? '' : $this->workerKey,
        ]));

        $process = new Process(
            command: ['node', $this->launchScriptPath],
            cwd: $this->workingDirectory,
            env: $env,
        );

        $process->setTimeout(null);       // long-lived; no per-invocation ceiling
        $process->setIdleTimeout(null);
        $process->start();
        $this->process = $process;

        $this->registerShutdownHandlerOnce();

        $deadline = microtime(true) + $this->startupTimeoutSeconds;
        $stdoutSoFar = '';

        while (microtime(true) < $deadline) {
            $stdoutSoFar .= $process->getIncrementalOutput();

            $handshakeLine = $this->extractFirstJsonLine($stdoutSoFar);

            if ($handshakeLine !== null) {
                $decoded = json_decode($handshakeLine, true);

                if (is_array($decoded) && isset($decoded['wsEndpoint']) && is_string($decoded['wsEndpoint']) && $decoded['wsEndpoint'] !== '') {
                    $this->wsEndpoint = $decoded['wsEndpoint'];

                    return;
                }
            }

            if (! $process->isRunning()) {
                break;
            }

            usleep(50_000);
        }

        // Startup failed or timed out. Capture output for diagnostics.
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $this->shutdown();

        throw new RuntimeException(sprintf(
            'pest-e2e warm browser did not become ready within %ds (worker=%s).%s',
            $this->startupTimeoutSeconds,
            $this->workerKey,
            ($stderr !== '' || $stdout !== '')
                ? "\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}"
                : '',
        ));
    }

    private function registerShutdownHandlerOnce(): void
    {
        if ($this->shutdownHandlerRegistered) {
            return;
        }

        $this->shutdownHandlerRegistered = true;

        register_shutdown_function(static function (): void {
            self::stopAll();
        });
    }

    private function extractFirstJsonLine(string $stdout): ?string
    {
        // Handshake protocol: first newline-terminated line of stdout is the
        // JSON handshake. Only parse a completed line (has \n at the end).
        $newlinePos = strpos($stdout, "\n");

        if ($newlinePos === false) {
            return null;
        }

        return trim(substr($stdout, 0, $newlinePos));
    }

    private static function defaultLaunchScriptPath(): string
    {
        return dirname(__DIR__, 2).'/resources/js/pest-e2e/warmBrowserServer.mjs';
    }

    /**
     * Test hook: reset the singleton registry (all managers shut down).
     *
     * @internal
     */
    public static function resetInstances(): void
    {
        self::stopAll();
        self::$instances = [];
    }
}
