<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

use Illuminate\Http\JsonResponse;
use ValcuAndrei\PestE2E\DTO\AuthPayloadDTO;

/**
 * @internal
 */
interface E2EAuthActionContract
{
    /**
     * Handle the auth payload.
     */
    public function handle(AuthPayloadDTO $payload): JsonResponse;
}
