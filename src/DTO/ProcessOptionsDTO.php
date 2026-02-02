<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class ProcessOptionsDTO
{
    public function __construct(
        public ?int $timeoutSeconds = null,
        public bool $inheritTty = false,
    ) {}

    /**
     * Create a new ProcessOptionsDTO instance with the given timeout seconds.
     */
    public function withTimeoutSeconds(?int $timeoutSeconds): self
    {
        return new self(
            timeoutSeconds: $timeoutSeconds,
            inheritTty: $this->inheritTty,
        );
    }

    /**
     * Create a new ProcessOptionsDTO instance with the given inherit TTY.
     */
    public function withInheritTty(bool $inheritTty): self
    {
        return new self(
            timeoutSeconds: $this->timeoutSeconds,
            inheritTty: $inheritTty,
        );
    }
}
