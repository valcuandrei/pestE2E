<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use RuntimeException;
use ValcuAndrei\PestE2E\DTO\ProjectConfigDTO;

/**
 * Builder passed to e2e()->project('name', fn($p) => ...)
 */
final class ProjectBuilder
{
    private ?string $dir = null;
    private ?string $runner = null; // informational only
    private ?string $command = null;

    private ?string $reportType = null;
    private ?string $reportPath = null;

    /** @var array<string,string> */
    private array $env = [];

    /** @var array<string,mixed> */
    private array $params = [];

    public function __construct(
        private readonly string $name,
    ) {}

    public function dir(string $dir): self
    {
        $this->dir = $dir;
        return $this;
    }

    public function runner(string $runner): self
    {
        $this->runner = $runner;
        return $this;
    }

    public function command(string $command): self
    {
        // Per spec: MUST NOT include sail/docker wrappers — enforcement can be added later.
        $this->command = $command;
        return $this;
    }

    public function report(string $type, string $path): self
    {
        $this->reportType = $type;
        $this->reportPath = $path;
        return $this;
    }

    /** @param array<string,string> $env */
    public function env(array $env): self
    {
        $this->env = array_replace($this->env, $env);
        return $this;
    }

    /** @param array<string,mixed> $params */
    public function params(array $params): self
    {
        $this->params = array_replace($this->params, $params);
        return $this;
    }

    public function toProjectConfig(): ProjectConfigDTO
    {
        if ($this->dir === null) {
            throw new RuntimeException("E2E project '{$this->name}' is missing dir().");
        }

        if ($this->command === null) {
            throw new RuntimeException("E2E project '{$this->name}' is missing command().");
        }

        if ($this->reportType === null || $this->reportPath === null) {
            throw new RuntimeException("E2E project '{$this->name}' is missing report(type, path).");
        }

        // Adjust ctor args to your real DTO signature if needed.
        return new ProjectConfigDTO(
            name: $this->name,
            dir: $this->dir,
            runner: $this->runner ?? 'unknown',
            command: $this->command,
            reportType: $this->reportType,
            reportPath: $this->reportPath,
            env: $this->env,
            params: $this->params,
        );
    }
}
