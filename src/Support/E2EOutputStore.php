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

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
