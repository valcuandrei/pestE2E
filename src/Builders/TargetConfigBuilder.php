<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Builders;

use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;

/**
 * @internal
 */
final class TargetConfigBuilder
{
    private string $dir = '.';

    private string $command = '';

    private string $reportType = 'json';

    private string $reportPath = '';

    /** @var array<string,string> */
    private array $env = [];

    /** @var array<string,mixed> */
    private array $params = [];

    /**
     * Create a new TargetConfigBuilder instance.
     */
    public function __construct(private readonly string $name) {}

    /**
     * Set the directory of the target.
     *
     *
     * @return $this
     */
    public function dir(string $dir): self
    {
        $this->dir = $dir;

        return $this;
    }

    /**
     * Set the command of the target.
     *
     *
     * @return $this
     */
    public function command(string $command): self
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Set the report of the target.
     *
     *
     * @return $this
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
     * @param  array<string, string>  $env
     * @param  array<string,string>  $env
     * @return $this
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
     * @return $this
     */
    public function params(array $params): self
    {
        /** @var array<string,mixed> $merged */
        $merged = array_replace_recursive($this->params, $params);
        $this->params = $merged;

        return $this;
    }

    /**
     * Convert the target config to a DTO.
     */
    public function toDTO(): TargetConfigDTO
    {
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
