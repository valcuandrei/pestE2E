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
        $status = $ok ?
            TestStatusType::PASSED->getSymbol().' PASSED'
            : TestStatusType::FAILED->getSymbol().' FAILED';
        $suffix = '';

        if ($stats instanceof JsonReportStatsDTO) {
            $duration = $this->formatDurationFromStats($stats, $durationSeconds);
            $suffix = sprintf(' (%s)', $duration);
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
     * @param  array<int, JsonReportTestDTO>  $tests
     * @return array<int, string>
     */
    private function buildTestLines(array $tests): array
    {
        $lines = [];

        foreach ($tests as $test) {
            $lines[] = $this->childIndent().$test->status->getSymbol().' <fg=gray>'.$test->name.'</fg=gray>';

            if ($test->status === TestStatusType::FAILED && $test->error?->message !== null) {
                $message = $this->stripAnsiEscapeSequences(trim($test->error->message));
                $lines = array_merge(
                    $lines,
                    $this->indentLines($this->splitLines('<fg=red>'.$message.'</fg=red>'), $this->errorIndent())
                );
            }

            if (! empty($test->extraLines)) {
                foreach ($test->extraLines as $extraLine) {
                    $lines[] = $this->childIndent().$extraLine;
                }

                $lines[] = '';
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
     * Strip ANSI escape sequences (e.g. cursor movement, line erase) from a string.
     * Playwright and other CLIs emit these for progress display; when captured and
     * re-displayed, they corrupt the output (e.g. \e[1A\e[2K erases the visible line).
     */
    private function stripAnsiEscapeSequences(string $text): string
    {
        return preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $text) ?? $text;
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
