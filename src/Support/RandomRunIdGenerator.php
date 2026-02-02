<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;

/**
 * @internal
 */
final class RandomRunIdGenerator implements RunIdGeneratorContract
{
    /**
     * Generate a run ID.
     */
    public function generate(): string
    {
        return bin2hex(random_bytes(8));
    }
}
