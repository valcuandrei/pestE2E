<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

/**
 * @internal
 */
interface E2EAuthActionContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array;
}
