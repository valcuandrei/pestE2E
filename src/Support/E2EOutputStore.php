<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;

/**
 * @internal
 */
final class E2EOutputStore
{
    /**
     * Static storage to persist entries across Laravel container refreshes.
     * This is needed because Pest's Plugin::addOutput() is called after all tests
     * complete, at which point the original Laravel container (and its singletons)
     * may have been destroyed and recreated.
     *
     * @var array<int, E2EOutputEntryDTO>
     */
    private static array $staticEntries = [];

    /**
     * Per-test storage for inline output (keyed by PHPUnit test ID).
     *
     * @var array<string, array<int, E2EOutputEntryDTO>>
     */
    private static array $perTestEntries = [];

    /** @var array<int, E2EOutputEntryDTO> */
    private array $entries = [];

    /**
     * @param  array<int, string>  $lines
     */
    public function add(
        array $lines,
        string $type,
        string $target,
        string $runId,
        bool $ok,
        ?float $durationSeconds,
        ?JsonReportStatsDTO $stats,
    ): void {
        $normalizedLines = array_values(array_map(
            static fn (string $line): string => $line,
            $lines,
        ));

        $entry = new E2EOutputEntryDTO(
            type: $type,
            target: $target,
            runId: $runId,
            ok: $ok,
            durationSeconds: $durationSeconds,
            stats: $stats,
            lines: $normalizedLines,
        );

        $this->entries[] = $entry;
        self::$staticEntries[] = $entry;
    }

    /**
     * Store an E2E output entry for a specific PHPUnit test ID.
     */
    public function putForTest(string $testId, E2EOutputEntryDTO $entry): void
    {
        if (! isset(self::$perTestEntries[$testId])) {
            self::$perTestEntries[$testId] = [];
        }

        self::$perTestEntries[$testId][] = $entry;
    }

    /**
     * Get all E2E output entries for a specific PHPUnit test ID.
     *
     * @return array<int, E2EOutputEntryDTO>
     */
    public function getForTest(string $testId): array
    {
        return self::$perTestEntries[$testId] ?? [];
    }

    /**
     * Get all per-test entries.
     *
     * @return array<string, array<int, E2EOutputEntryDTO>>
     */
    public function getAllPerTestEntries(): array
    {
        return self::$perTestEntries;
    }

    /**
     * Remove all entries for a specific test ID (for cleanup).
     */
    public function removeForTest(string $testId): void
    {
        unset(self::$perTestEntries[$testId]);
    }

    /**
     * @return array<int, E2EOutputEntryDTO>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * @return array<int, E2EOutputEntryDTO>
     */
    public function flush(): array
    {
        // Return from static storage to survive container refreshes
        $entries = self::$staticEntries;
        self::$staticEntries = [];
        $this->entries = [];

        return $entries;
    }

    /**
     * Clear all per-test entries (for test isolation).
     */
    public function flushPerTestEntries(): void
    {
        self::$perTestEntries = [];
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
