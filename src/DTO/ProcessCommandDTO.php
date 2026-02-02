<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class ProcessCommandDTO
{
    /**
     * @param  string  $command  Raw command string (executed as-is).
     * @param  array<string, string>  $env
     * @param  array<string, string>  $injectedEnv  (optional) additional env to apply last
     */
    public function __construct(
        public string $command,
        public string $workingDirectory,
        public array $env = [],
        public array $injectedEnv = [],
    ) {}

    /**
     * Create a new ProcessCommandDTO instance with the given environment variables.
     *
     * @param  array<string, string>  $env
     */
    public function withEnv(array $env): self
    {
        return new self(
            command: $this->command,
            workingDirectory: $this->workingDirectory,
            env: array_replace($this->env, $env),
            injectedEnv: $this->injectedEnv,
        );
    }

    /**
     * Injected env applied last (useful for injected PEST_E2E_* vars).
     *
     * @param  array<string, string>  $env
     */
    public function withInjectedEnv(array $env): self
    {
        return new self(
            command: $this->command,
            workingDirectory: $this->workingDirectory,
            env: $this->env,
            injectedEnv: array_replace($this->injectedEnv, $env),
        );
    }

    /**
     * Get the merged environment variables. Returns a new array.
     *
     * @return array<string, string>
     */
    public function getMergedEnv(): array
    {
        return array_replace($this->env, $this->injectedEnv);
    }
}
