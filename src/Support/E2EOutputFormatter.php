<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\Enums\TestStatusType;

/**
 * @internal
 */
final class E2EOutputFormatter
{
    public const BASE_INDENT = '      ';

    public const BRANCH_PREFIX = '└─ ';

    public const CHILD_INDENT = '   ';

    public const ERROR_INDENT = '  ';

    /**
     * @param  array<int, JsonReportTestDTO>  $tests
     * @param  array<int, string>  $extraLines
     * @return array<int, string>
     */
    public function buildRunLines(
        string $target,
        string $runId,
        bool $ok,
        ?float $durationSeconds,
        ?JsonReportStatsDTO $stats,
        array $tests,
        ?string $parentTestName,
        array $extraLines,
    ): array {
        $status = $ok ? '✅ PASSED' : '❌ FAILED';
        $suffix = '';

        if ($stats instanceof JsonReportStatsDTO) {
            $duration = $this->formatDurationFromStats($stats, $durationSeconds);
            $suffix = sprintf(
                ' (passed=%d failed=%d skipped=%d, %s)',
                $stats->passed,
                $stats->failed,
                $stats->skipped,
                $duration,
            );
        } elseif ($durationSeconds !== null) {
            $suffix = ' ('.$this->formatDurationSeconds($durationSeconds).')';
        }

        $parentTestName = $this->normalizeParentTestName($parentTestName);

        if ($parentTestName === null) {
            $header = sprintf('PestE2E: target "%s" runId "%s" %s%s', $target, $runId, $status, $suffix);

            return array_merge([$header], $extraLines);
        }

        $header = sprintf('E2E › %s (runId %s) %s%s', $target, $runId, $status, $suffix);
        $lines = [
            $parentTestName,
            $this->branchLine($header),
        ];

        if ($tests !== []) {
            $lines = array_merge($lines, $this->buildTestLines($tests));
        }

        if ($stats instanceof JsonReportStatsDTO) {
            $lines[] = $this->childIndent().sprintf(
                'passed=%d failed=%d skipped=%d  duration=%s',
                $stats->passed,
                $stats->failed,
                $stats->skipped,
                $this->formatDurationFromStats($stats, $durationSeconds),
            );
        }

        if ($extraLines !== []) {
            return array_merge($lines, $this->indentLines($extraLines, $this->childIndent()));
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $extraLines
     * @return array<int, string>
     */
    public function buildCallLines(
        string $target,
        string $resolvedTarget,
        string $runId,
        bool $ok,
        ?float $durationSeconds,
        ?string $parentTestName,
        array $extraLines,
    ): array {
        $status = $ok ? '✅ PASSED' : '❌ FAILED';
        $suffix = $durationSeconds !== null ? ' ('.$this->formatDurationSeconds($durationSeconds).')' : '';

        $parentTestName = $this->normalizeParentTestName($parentTestName);

        if ($parentTestName === null) {
            $header = sprintf(
                'PestE2E: target "%s" call "%s" runId "%s" %s%s',
                $target,
                $resolvedTarget,
                $runId,
                $status,
                $suffix,
            );

            return array_merge([$header], $extraLines);
        }

        $header = sprintf(
            'E2E › %s (call %s, runId %s) %s%s',
            $target,
            $resolvedTarget,
            $runId,
            $status,
            $suffix,
        );

        $lines = [
            $parentTestName,
            $this->branchLine($header),
        ];

        if ($extraLines !== []) {
            return array_merge($lines, $this->indentLines($extraLines, $this->childIndent()));
        }

        return $lines;
    }

    /**
     * @param  array<int, JsonReportTestDTO>  $tests
     * @return array<int, string>
     */
    private function buildTestLines(array $tests): array
    {
        $lines = [];

        foreach ($tests as $test) {
            $lines[] = $this->childIndent().$test->status->getSymbol().' <fg=gray>'.$test->name.'</fg=gray>';

            if ($test->status === TestStatusType::FAILED && $test->error?->message !== null) {
                $lines = array_merge(
                    $lines,
                    $this->indentLines($this->splitLines('<fg=red>'.$test->error->message.'</fg=red>'), $this->errorIndent())
                );
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function indentLines(array $lines, string $indent): array
    {
        $indented = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $indented[] = $indent.$trimmed;
        }

        return $indented;
    }

    private function branchLine(string $line): string
    {
        return self::BASE_INDENT.self::BRANCH_PREFIX.$line;
    }

    private function childIndent(): string
    {
        return self::BASE_INDENT.self::CHILD_INDENT;
    }

    private function errorIndent(): string
    {
        return $this->childIndent().self::ERROR_INDENT;
    }

    private function normalizeParentTestName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : $name;
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $message): array
    {
        return array_values(array_filter(
            preg_split('/\R/', $message) ?: [],
            static fn (string $line): bool => $line !== '',
        ));
    }

    private function formatDurationFromStats(JsonReportStatsDTO $stats, ?float $durationSeconds): string
    {
        if ($stats->durationMs > 0) {
            return $stats->durationMs.'ms';
        }

        if ($durationSeconds === null) {
            return '0ms';
        }

        return $this->formatDurationSeconds($durationSeconds);
    }

    private function formatDurationSeconds(float $durationSeconds): string
    {
        if ($durationSeconds < 1) {
            return max(1, (int) round($durationSeconds * 1000)).'ms';
        }

        return number_format($durationSeconds, 2).'s';
    }
}
