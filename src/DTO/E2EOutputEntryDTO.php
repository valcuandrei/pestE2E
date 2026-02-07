<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class E2EOutputEntryDTO
{
    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public string $type,
        public string $target,
        public string $runId,
        public bool $ok,
        public ?float $durationSeconds,
        public ?JsonReportStatsDTO $stats,
        public array $lines,
    ) {}
}
