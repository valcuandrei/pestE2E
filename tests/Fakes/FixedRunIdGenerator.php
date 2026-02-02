<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Fakes;

use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;

final class FixedRunIdGenerator implements RunIdGeneratorContract
{
    /**
     * Create a new FixedRunIdGenerator instance.
     */
    public function __construct(
        private readonly string $runId = 'run-123',
    ) {}

    /**
     * Generate a run ID.
     */
    public function generate(): string
    {
        return $this->runId;
    }
}
