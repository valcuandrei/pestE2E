<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

/**
 * @internal
 */
interface RunIdGeneratorContract
{
    /**
     * Generate a run ID.
     */
    public function generate(): string;
}
