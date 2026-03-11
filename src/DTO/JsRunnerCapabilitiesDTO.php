<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

final readonly class JsRunnerCapabilitiesDTO
{
    /**
     * Create a new JS runner capabilities descriptor.
     */
    public function __construct(
        public bool $supportsPersistentRuntime = false,
        public bool $requiresExplicitStart = false,
    ) {}
}
