<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class ServerRunner
{
    private ?Process $process = null;

    private string $host = '127.0.0.1';

    private int $port = 0;

    /**
     * Start a managed Laravel server, run the callback, then stop the server.
     *
     * The callback receives ($baseUrl).
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function run(callable $callback, bool $keepAliveOnFailure = false)
    {
        if (! $this->canServeLaravelApp()) {
            // We are in a non-servable environment (Testbench/package context).
            // Use whatever app URL is already configured.
            $baseUrl = rtrim(config()->string('app.url', 'http://127.0.0.1'), '/');

            return $callback($baseUrl);
        }

        $this->start();

        // Safety-net: if the PHP process dies (fatal, exit, etc), attempt cleanup.
        $this->registerShutdownHandler();

        try {
            return $callback($this->baseUrl());
        } finally {
            if (! $keepAliveOnFailure) {
                $this->stop();
            }
        }
    }

    /**
     * Start the server and wait until it responds to HTTP.
     */
    public function start(): void
    {
        if ($this->process instanceof Process && $this->process->isRunning()) {
            return;
        }

        $this->port = $this->findFreePort($this->host);

        // Prefer explicit env injection; Laravel will load .env.testing when APP_ENV=testing.
        // Also keep auth route enabled for the managed server by default.
        $env = [
            'APP_ENV' => 'testing',
            'PEST_E2E_AUTH_ROUTE_ENABLED' => 'true',
            'APP_URL' => $this->baseUrl(),
        ];

        $this->process = new Process(
            [
                'php',
                'artisan',
                'serve',
                '--env=testing',
                "--host={$this->host}",
                "--port={$this->port}",
            ],
            base_path(),
            $env,
            null,
            null, // timeout handled via readiness wait; process itself should not timeout
        );

        $this->process->setTimeout(null);
        $this->process->start();

        $this->waitUntilReady(
            baseUrl: $this->baseUrl(),
            timeoutSeconds: 12,
            probePath: '/__pest_e2e_ping', // will fall back to '/' if 404
        );
    }

    /**
     * Stop the server process.
     */
    public function stop(): void
    {
        if (! ($this->process instanceof Process)) {
            return;
        }

        if (! $this->process->isRunning()) {
            $this->process = null;

            return;
        }

        // Try graceful, then hard.
        // stop() does SIGTERM then SIGKILL after timeout on Unix; on Windows it terminates the process.
        $this->process->stop(2);

        // Extra belt: if still running, force kill now.
        if ($this->process->isRunning()) { // @phpstan-ignore-line
            $this->process->stop(0);
        }

        $this->process = null;
    }

    public function baseUrl(): string
    {
        if ($this->port <= 0) {
            return "http://{$this->host}";
        }

        return "http://{$this->host}:{$this->port}";
    }

    public function port(): int
    {
        return $this->port;
    }

    public function process(): ?Process
    {
        return $this->process;
    }

    /**
     * Wait until the server responds. This is real readiness, not "process started".
     *
     * Strategy:
     *  - If process exits early -> throw with output
     *  - Poll HTTP (probePath, then '/')
     *  - If HTTP responds with *any* status code, we consider it "up"
     *    (even 404 proves the server is accepting requests)
     */
    private function waitUntilReady(string $baseUrl, int $timeoutSeconds, string $probePath): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $process = $this->requireProcess();

            // If it died during boot, fail fast with context.
            if (! $process->isRunning()) {
                $out = trim($process->getOutput());
                $err = trim($process->getErrorOutput());

                throw new RuntimeException(
                    "Managed server exited before becoming ready.\n\nSTDOUT:\n{$out}\n\nSTDERR:\n{$err}\n"
                );
            }

            // Probe preferred path first (may be 404 - that's fine; we just want a response)
            if ($this->httpResponds($baseUrl.$probePath) || $this->httpResponds($baseUrl.'/')) {
                return;
            }

            usleep(100_000); // 100ms
        }

        $process = $this->requireProcess();
        $out = trim($process->getOutput());
        $err = trim($process->getErrorOutput());

        throw new RuntimeException(
            "Managed server did not become ready within {$timeoutSeconds}s at {$baseUrl}.\n\nSTDOUT:\n{$out}\n\nSTDERR:\n{$err}\n"
        );
    }

    /**
     * Returns true if an HTTP request gets *any* response.
     * Uses streams to avoid requiring curl extension.
     */
    private function httpResponds(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 0.5, // seconds
                'ignore_errors' => true, // allow reading response headers on 404/500
                'header' => "Connection: close\r\n",
            ],
        ]);

        $fp = @fopen($url, 'r', false, $context);
        if ($fp === false) {
            return false;
        }

        fclose($fp);

        // If fopen succeeded, server responded (status may be 200/404/500—still "up").
        return true;
    }

    /**
     * Find an available TCP port on the given host.
     */
    private function findFreePort(string $host): int
    {
        $socket = @stream_socket_server("tcp://{$host}:0", $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Unable to allocate a free port: {$errstr} ({$errno})");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            throw new RuntimeException("Unable to determine chosen port from socket name: {$name}");
        }

        $pos = strrpos($name, ':');
        if ($pos === false) {
            throw new RuntimeException("Unable to determine chosen port from socket name: {$name}");
        }

        return (int) substr($name, $pos + 1);
    }

    private function requireProcess(): Process
    {
        if (! ($this->process instanceof Process)) {
            throw new RuntimeException('Server process was not started.');
        }

        return $this->process;
    }

    /**
     * Best-effort cleanup for fatal errors / unexpected exits.
     * Not a replacement for try/finally, just a safety net.
     */
    private function registerShutdownHandler(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;

        register_shutdown_function(function (): void {
            try {
                $this->stop();
            } catch (\Throwable) {
                // swallow — shutdown handlers must never throw
            }
        });
    }

    /**
     * Check if the current directory is a Laravel application.
     */
    private function canServeLaravelApp(): bool
    {
        return is_file(base_path('artisan'))
            && is_file(base_path('public/index.php'))
            && is_file(base_path('vendor/autoload.php'));
    }
}
