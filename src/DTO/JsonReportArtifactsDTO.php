<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class JsonReportArtifactsDTO
{
    /**
     * @param  array<int, string>  $screenshots
     */
    public function __construct(
        public ?string $trace = null,
        public ?string $video = null,
        public array $screenshots = [],
    ) {}
}
