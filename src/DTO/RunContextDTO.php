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
        public TargetConfigDTO $target,
        public string $runId,
        public array $env,
        public array $params,
        public ?string $testFilter = null,
    ) {}

    /**
     * Create a new RunContextDTO instance.
     *
     * @param  array<string, string>  $env
     * @param  array<string, mixed>  $params
     */
    public static function make(
        TargetConfigDTO $target,
        string $runId,
        array $env = [],
        array $params = [],
        ?string $testFilter = null,
    ): self {
        /** @var array<string, mixed> */
        $mergedParams = array_replace_recursive($target->params, $params);

        return new self(
            target: $target,
            runId: $runId,
            env: array_replace($target->env, $env),
            params: $mergedParams,
            testFilter: $testFilter,
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
            target: $this->target,
            runId: $this->runId,
            env: array_replace($this->env, $env),
            params: $this->params,
            testFilter: $this->testFilter,
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
            target: $this->target,
            runId: $this->runId,
            env: $this->env,
            params: $mergedParams,
            testFilter: $this->testFilter,
        );
    }
}
