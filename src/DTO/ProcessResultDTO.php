<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class ProcessResultDTO
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $durationSeconds,
    ) {}

    /**
     * Check if the process was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
