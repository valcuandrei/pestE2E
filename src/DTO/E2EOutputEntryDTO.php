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
     * @param  array<int, array{name: string, js_file: ?string, message: ?string, stack: ?string}>  $failures
     */
    public function __construct(
        public string $type,
        public string $target,
        public string $runId,
        public bool $ok,
        public ?float $durationSeconds,
        public ?JsonReportStatsDTO $stats,
        public array $lines,
        public ?string $reportDirectory = null,
        public ?string $phpTestFile = null,
        public ?string $phpTestName = null,
        public array $failures = [],
        public ?string $errorMessage = null,
        public ?string $errorStack = null,
    ) {}
}
