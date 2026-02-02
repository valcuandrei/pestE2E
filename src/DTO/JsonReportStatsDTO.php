<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class JsonReportStatsDTO
{
    public function __construct(
        public int $passed,
        public int $failed,
        public int $skipped,
        public int $durationMs,
    ) {}
}
