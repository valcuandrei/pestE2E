<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;

/**
 * @internal
 */
final class AgentOutputSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function fromEntry(E2EOutputEntryDTO $entry): array
    {
        $stats = $entry->stats;
        $passed = $stats instanceof JsonReportStatsDTO ? $stats->passed : 0;
        $failed = $stats instanceof JsonReportStatsDTO ? $stats->failed : ($entry->ok ? 0 : 1);
        $durationMs = $stats instanceof JsonReportStatsDTO
            ? $stats->durationMs
            : max(0, (int) round(($entry->durationSeconds ?? 0) * 1000));

        $summary = [
            'target' => $entry->target,
            'result' => $entry->ok ? 'passed' : 'failed',
            'passed' => $passed,
            'failed' => $failed,
            'duration_ms' => $durationMs,
            'report_dir' => $entry->reportDirectory,
        ];

        if ($entry->phpTestFile !== null || $entry->phpTestName !== null) {
            $summary['php_test'] = array_filter([
                'file' => $entry->phpTestFile,
                'name' => $entry->phpTestName,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        if (! $entry->ok) {
            if ($entry->failures !== []) {
                $summary['failures'] = $entry->failures;
            }

            if ($entry->errorMessage !== null && trim($entry->errorMessage) !== '') {
                $summary['error'] = array_filter([
                    'message' => $entry->errorMessage,
                    'stack' => $entry->errorStack,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            }
        }

        return $summary;
    }

    public static function encode(E2EOutputEntryDTO $entry): string
    {
        return json_encode(
            self::fromEntry($entry),
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }
}
