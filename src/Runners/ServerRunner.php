<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use RuntimeException;
use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Enums\ServerRunnerType;
use ValcuAndrei\PestE2E\Support\TimingProbe;

/**
 * @internal
 */
final class ServerRunner
{
    private ?Process $process = null;

    private string $host = '127.0.0.1';

    private int $port = 0;

    public function __construct(
        private readonly ServerRunnerType $type = ServerRunnerType::ARTISAN,
    ) {}

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
            // Non-servable environment (Testbench/package context).
            // Use whatever app URL is already configured.
            $baseUrl = rtrim(config()->string('app.url', 'http://127.0.0.1'), '/');

            return $callback($baseUrl);
        }

        $this->start();
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

        $startedAt = microtime(true);
        TimingProbe::mark('server_runner_start', [
            'driver' => $this->type->value,
        ]);

        $this->port = $this->findFreePort($this->host);

        $env = array_merge($_ENV, [
            'APP_ENV' => 'testing',
            'PEST_E2E_AUTH_ROUTE_ENABLED' => 'true',
            'APP_URL' => $this->baseUrl(),
            'PEST_E2E_BASE_PATH' => base_path(),
        ]);

        $this->process = new Process(
            $this->command(),
            base_path(),
            $env,
            null,
            null
        );

        $this->process->setTimeout(null);
        $this->process->start();

        $this->waitUntilReady(
            baseUrl: $this->baseUrl(),
            timeoutSeconds: 12,
            probePath: config()->string('pest-e2e.auth.route', '/__pest_e2e_ping'),
        );

        TimingProbe::mark('server_ready', [
            'baseUrl' => $this->baseUrl(),
            'port' => $this->port,
            'durationMs' => TimingProbe::elapsedMs($startedAt),
        ]);
    }

    /**
     * Stop the server process.
     */
    public function stop(): void
    {
        $process = $this->process;

        if (! ($process instanceof Process)) {
            return;
        }

        if (! $process->isRunning()) {
            $this->process = null;

            return;
        }

        $process->stop(2);

        $this->process = null;
    }

    /**
     * Command: artisan serve (Laravel wrapper).
     *
     * @return list<string>
     */
    private function artisanCommand(): array
    {
        return [
            'php',
            'artisan',
            'serve',
            '--env=testing',
            "--host={$this->host}",
            "--port={$this->port}",
        ];
    }

    /**
     * The command to start the server.
     *
     * @return list<string>
     */
    private function command(): array
    {
        return match ($this->type) {
            ServerRunnerType::ARTISAN => $this->artisanCommand(),
        };
    }

    public function baseUrl(): string
    {
        return $this->port > 0
            ? "http://{$this->host}:{$this->port}"
            : "http://{$this->host}";
    }

    public function port(): int
    {
        return $this->port;
    }

    public function process(): ?Process
    {
        return $this->process;
    }

    private function waitUntilReady(string $baseUrl, int $timeoutSeconds, string $probePath): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $process = $this->requireProcess();

            if (! $process->isRunning()) {
                $out = trim($process->getOutput());
                $err = trim($process->getErrorOutput());

                throw new RuntimeException(
                    "Managed server exited before becoming ready.\n\nSTDOUT:\n{$out}\n\nSTDERR:\n{$err}\n"
                );
            }

            if ($this->httpResponds($baseUrl.$probePath) || $this->httpResponds($baseUrl.'/')) {
                return;
            }

            usleep(100_000);
        }

        $process = $this->requireProcess();
        $out = trim($process->getOutput());
        $err = trim($process->getErrorOutput());

        throw new RuntimeException(
            "Managed server did not become ready within {$timeoutSeconds}s at {$baseUrl}.\n\nSTDOUT:\n{$out}\n\nSTDERR:\n{$err}\n"
        );
    }

    private function httpResponds(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 0.5,
                'ignore_errors' => true,
                'header' => "Connection: close\r\n",
            ],
        ]);

        $fp = @fopen($url, 'r', false, $context);
        if ($fp === false) {
            return false;
        }

        fclose($fp);

        return true;
    }

    private function findFreePort(string $host): int
    {
        $socket = @stream_socket_server("tcp://{$host}:0", $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Unable to allocate a free port: {$errstr} ({$errno})");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            throw new RuntimeException('Unable to determine chosen port from socket name.');
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
                // shutdown handlers must never throw
            }
        });
    }

    private function canServeLaravelApp(): bool
    {
        return is_file(base_path('artisan'))
            && is_file(base_path('public/index.php'))
            && is_file(base_path('vendor/autoload.php'));
    }
}
