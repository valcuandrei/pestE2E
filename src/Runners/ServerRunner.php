<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Runners;

use RuntimeException;
use Symfony\Component\Process\Process;
use ValcuAndrei\PestE2E\Enums\ServerRunnerType;

/**
 * @internal
 */
final class ServerRunner
{
    private ?Process $process = null;

    private string $host = '127.0.0.1';

    private int $port = 0;

    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(
        private readonly ServerRunnerType $type = ServerRunnerType::ARTISAN,
    ) {}

    /**
     * Get the instance of the server runner.
     */
    public static function instance(ServerRunnerType $type = ServerRunnerType::ARTISAN): self
    {
        return self::$instances[$type->value] ??= new self($type);
    }

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
    public function whenReady(callable $callback)
    {
        if (! $this->isRunning()) {
            if ($this->canServeLaravelApp() && ! empty($_ENV['IS_E2E_TEST'])) {
                $this->start();
            } else {
                $baseUrl = rtrim(config()->string('app.url', 'http://127.0.0.1'), '/');

                return $callback($baseUrl);
            }
        }

        $this->waitUntilReady(
            baseUrl: $this->baseUrl(),
            timeoutSeconds: 12,
            probePath: $this->probePath(),
        );

        return $callback($this->baseUrl());
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

        $basePath = $this->basePath();

        $env = array_merge($_ENV, [
            'APP_ENV' => 'testing',
            'PEST_E2E_AUTH_ROUTE_ENABLED' => 'true',
            'APP_URL' => $this->baseUrl(),
            'PEST_E2E_BASE_PATH' => $basePath,
        ]);

        $this->process = new Process(
            $this->command(),
            $basePath,
            $env,
            null,
            null
        );

        $this->process->setTimeout(null);
        $this->process->start();
        $this->registerShutdownHandler();
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

    /**
     * The base URL of the server.
     */
    public function baseUrl(): string
    {
        return $this->port > 0
            ? "http://{$this->host}:{$this->port}"
            : "http://{$this->host}";
    }

    /**
     * Check if the server is running.
     */
    public function isRunning(): bool
    {
        return $this->process instanceof Process && $this->process->isRunning();
    }

    /**
     * Get the port of the server.
     */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * Get the process of the server.
     */
    public function process(): ?Process
    {
        return $this->process;
    }

    /**
     * Wait until the server is ready.
     */
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

            usleep(10_000);
        }

        $process = $this->requireProcess();
        $out = trim($process->getOutput());
        $err = trim($process->getErrorOutput());

        throw new RuntimeException(
            "Managed server did not become ready within {$timeoutSeconds}s at {$baseUrl}.\n\nSTDOUT:\n{$out}\n\nSTDERR:\n{$err}\n"
        );
    }

    /**
     * Check if the server responds to HTTP.
     */
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

    /**
     * Find a free port.
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
            throw new RuntimeException('Unable to determine chosen port from socket name.');
        }

        $pos = strrpos($name, ':');
        if ($pos === false) {
            throw new RuntimeException("Unable to determine chosen port from socket name: {$name}");
        }

        return (int) substr($name, $pos + 1);
    }

    /**
     * Require the process of the server.
     */
    private function requireProcess(): Process
    {
        if (! ($this->process instanceof Process)) {
            throw new RuntimeException('Server process was not started.');
        }

        return $this->process;
    }

    /**
     * Register a shutdown handler.
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
                // shutdown handlers must never throw
            }
        });
    }

    /**
     * Check if the server can serve a Laravel app.
     */
    private function canServeLaravelApp(): bool
    {
        $basePath = $this->resolveBasePath();

        if ($basePath === null) {
            return false;
        }

        return is_file($basePath.'/artisan')
            && is_file($basePath.'/public/index.php')
            && is_file($basePath.'/vendor/autoload.php');
    }

    /**
     * Get the base path of the server.
     */
    private function basePath(): string
    {
        $basePath = $this->resolveBasePath();

        if ($basePath === null) {
            throw new RuntimeException('Unable to locate Laravel project base path.');
        }

        return $basePath;
    }

    /**
     * Get the probe path of the server.
     */
    private function probePath(): string
    {
        $path = $_ENV['PEST_E2E_AUTH_ROUTE']
            ?? $_SERVER['PEST_E2E_AUTH_ROUTE']
            ?? getenv('PEST_E2E_AUTH_ROUTE');

        if (is_string($path) && $path !== '' && str_starts_with($path, '/')) {
            return $path;
        }

        return '/pest-e2e/auth/login';
    }

    /**
     * Resolve the base path of the server.
     */
    private function resolveBasePath(): ?string
    {
        $cwd = $_SERVER['PWD'] ?? getcwd();

        if (! is_string($cwd) || $cwd === '') {
            return null;
        }

        $dir = realpath($cwd) ?: $cwd;

        while ($dir !== dirname($dir)) {
            if (
                is_file($dir.'/artisan')
                && is_file($dir.'/public/index.php')
                && is_file($dir.'/vendor/autoload.php')
            ) {
                return $dir;
            }

            $dir = dirname($dir);
        }

        return null;
    }
}
