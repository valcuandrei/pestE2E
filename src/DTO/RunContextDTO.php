<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class RunContextDTO
{
    /**
     * @param  array<string, string>  $env
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public ProjectConfigDTO $project,
        public string $runId,
        public array $env,
        public array $params,
    ) {}

    /**
     * Create a new RunContextDTO instance.
     *
     * @param  array<string, string>  $env
     * @param  array<string, mixed>  $params
     */
    public static function make(
        ProjectConfigDTO $project,
        string $runId,
        array $env = [],
        array $params = [],
    ): self {
        /** @var array<string, mixed> */
        $mergedParams = array_replace_recursive($project->params, $params);

        return new self(
            project: $project,
            runId: $runId,
            env: array_replace($project->env, $env),
            params: $mergedParams,
        );
    }

    /**
     * Create a new RunContextDTO instance with the given environment variables.
     *
     * @param  array<string, string>  $env
     */
    public function withEnv(array $env): self
    {
        return new self(
            project: $this->project,
            runId: $this->runId,
            env: array_replace($this->env, $env),
            params: $this->params,
        );
    }

    /**
     * Create a new RunContextDTO instance with the given parameters.
     *
     * @param  array<string, mixed>  $params
     */
    public function withParams(array $params): self
    {
        /** @var array<string, mixed> */
        $mergedParams = array_replace_recursive($this->params, $params);

        return new self(
            project: $this->project,
            runId: $this->runId,
            env: $this->env,
            params: $mergedParams,
        );
    }
}
