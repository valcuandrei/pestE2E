<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class JsonReportErrorDTO
{
    public function __construct(
        public string $message,
        public ?string $stack = null,
    ) {}
}
