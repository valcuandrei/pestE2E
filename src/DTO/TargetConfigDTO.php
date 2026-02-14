<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class TargetConfigDTO
{
    /**
     * @param  array<string, string>  $env  (optional) environment variables
     * @param  array<string, mixed>  $params  (optional) parameters
     * @param  string|null  $artifactsDir  (optional) artifacts directory
     */
    public function __construct(
        public string $name,
        public string $dir,
        public string $command,
        public string $reportType,
        public string $reportPath,
        public array $env = [],
        public array $params = [],
        public ?string $artifactsDir = null,
    ) {}
}
