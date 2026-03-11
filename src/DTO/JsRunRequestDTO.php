<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

final readonly class JsRunRequestDTO
{
    /**
     * @param  array<string, string|null>  $env
     */
    public function __construct(
        public string $command,
        public string $workingDirectory,
        public array $env = [],
        public ?int $timeoutSeconds = null,
        public bool $inheritTty = false,
    ) {}

    /**
     * Create a JS run request from a process plan.
     */
    public static function fromProcessPlan(ProcessPlanDTO $plan): self
    {
        return new self(
            command: $plan->command->command,
            workingDirectory: $plan->command->workingDirectory,
            env: $plan->command->getMergedEnv(),
            timeoutSeconds: $plan->options->timeoutSeconds,
            inheritTty: $plan->options->inheritTty,
        );
    }
}
