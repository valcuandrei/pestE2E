<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Builders;

use ValcuAndrei\PestE2E\DTO\ProjectConfigDTO;

/**
 * @internal
 */
final class ProjectConfigBuilder
{
    private string $dir = '.';

    private string $runner = '';

    private string $command = '';

    private string $reportType = 'json';

    private string $reportPath = '';

    /** @var array<string,string> */
    private array $env = [];

    /** @var array<string,mixed> */
    private array $params = [];

    /**
     * Create a new ProjectConfigBuilder instance.
     */
    public function __construct(private readonly string $name) {}

    /**
     * Set the directory of the project.
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
     * Set the runner of the project.
     *
     *
     * @return $this
     */
    public function runner(string $runner): self
    {
        $this->runner = $runner;

        return $this;
    }

    /**
     * Set the command of the project.
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
     * Set the report of the project.
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
     * Set the environment variables of the project.
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
     * Set the parameters of the project.
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
     * Convert the project config to a DTO.
     */
    public function toDTO(): ProjectConfigDTO
    {
        return new ProjectConfigDTO(
            name: $this->name,
            dir: $this->dir,
            runner: $this->runner,
            command: $this->command,
            reportType: $this->reportType,
            reportPath: $this->reportPath,
            env: $this->env,
            params: $this->params,
        );
    }
}
