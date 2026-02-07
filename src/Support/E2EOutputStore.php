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

        $this->entries[] = new E2EOutputEntryDTO(
            type: $type,
            target: $target,
            runId: $runId,
            ok: $ok,
            durationSeconds: $durationSeconds,
            stats: $stats,
            lines: $normalizedLines,
        );
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
        $entries = $this->entries;
        $this->entries = [];

        return $entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
