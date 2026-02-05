<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract;

/**
 * @internal
 */
final class DefaultE2EAuthAction implements E2EAuthActionContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): array
    {
        return [];
    }
}
