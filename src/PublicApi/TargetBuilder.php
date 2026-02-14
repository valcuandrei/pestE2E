<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PublicApi;

use RuntimeException;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;

/**
 * Builder passed to e2e()->target('name', fn($p) => ...)
 */
final class TargetBuilder
{
    private ?string $dir = null;

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

    /**
     * Set the directory of the target.
     */
    public function dir(string $dir): self
    {
        $this->dir = $dir;

        return $this;
    }

    /**
     * Set the command of the target.
     */
    public function command(string $command): self
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Set the report of the target.
     */
    public function report(string $type, string $path): self
    {
        $this->reportType = $type;
        $this->reportPath = $path;

        return $this;
    }

    /**
     * Set the environment variables of the target.
     *
     * @param  array<string,string>  $env
     */
    public function env(array $env): self
    {
        $this->env = array_replace($this->env, $env);

        return $this;
    }

    /**
     * Set the parameters of the target.
     *
     * @param  array<string,mixed>  $params
     */
    public function params(array $params): self
    {
        $this->params = array_replace($this->params, $params);

        return $this;
    }

    /**
     * Convert the target builder to a TargetConfigDTO instance.
     *
     *
     * @throws RuntimeException
     */
    public function toTargetConfig(): TargetConfigDTO
    {
        if ($this->dir === null) {
            throw new RuntimeException("E2E target '{$this->name}' is missing dir().");
        }

        if ($this->command === null) {
            throw new RuntimeException("E2E target '{$this->name}' is missing command().");
        }

        if ($this->reportType === null || $this->reportPath === null) {
            throw new RuntimeException("E2E target '{$this->name}' is missing report(type, path).");
        }

        return new TargetConfigDTO(
            name: $this->name,
            dir: $this->dir,
            command: $this->command,
            reportType: $this->reportType,
            reportPath: $this->reportPath,
            env: $this->env,
            params: $this->params,
        );
    }
}
