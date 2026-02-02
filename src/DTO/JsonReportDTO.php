<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class JsonReportDTO
{
    /**
     * @param  array<int, JsonReportTestDTO>  $tests  (optional) tests
     */
    public function __construct(
        public string $schema,
        public string $project,
        public string $runId,
        public JsonReportStatsDTO $stats,
        public array $tests = [],
    ) {}

    /**
     * Check if the report has failures.
     */
    public function hasFailures(): bool
    {
        return $this->stats->failed > 0 || count($this->getFailedTests()) > 0;
    }

    /**
     * Get the failed tests from the report.
     *
     * @return array<int, JsonReportTestDTO>
     */
    public function getFailedTests(): array
    {
        return array_values(array_filter(
            $this->tests,
            static fn (JsonReportTestDTO $t): bool => $t->isFailed()
        ));
    }
}
