<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

final readonly class JsRunResultDTO
{
    /**
     * Create a new JS run result.
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $durationSeconds,
    ) {}

    /**
     * Check if the JS run completed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
