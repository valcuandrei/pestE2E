<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class ParamsPayloadDTO
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public ?string $jsonInline = null,
        public ?string $jsonFilePath = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function inline(array $payload, string $jsonInline): self
    {
        return new self(payload: $payload, jsonInline: $jsonInline);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function file(array $payload, string $jsonFilePath): self
    {
        return new self(payload: $payload, jsonInline: null, jsonFilePath: $jsonFilePath);
    }

    /**
     * Check if the payload uses a file.
     */
    public function usesFile(): bool
    {
        return $this->jsonFilePath !== null;
    }
}
