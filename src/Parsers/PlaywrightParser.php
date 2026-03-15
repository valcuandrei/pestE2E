<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Parsers;

use JsonException;
use ValcuAndrei\PestE2E\Contracts\JsonParserContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportErrorDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\Enums\TestStatusType;
use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;

/**
 * @internal
 */
final class PlaywrightParser implements JsonParserContract
{
    public function parse(string $json, string $target, string $runId): JsonReportDTO
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonReportParserException("Invalid JSON: {$e->getMessage()}", previous: $e);
        }

        if (! is_array($data)) {
            throw new JsonReportParserException('Invalid Playwright report: expected object root');
        }

        /** @var array<string, mixed> $data */
        /** @var array{passed:int,failed:int,skipped:int,durationMs:int} $stats */
        $stats = [
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'durationMs' => 0,
        ];

        /** @var list<JsonReportTestDTO> $tests */
        $tests = [];
        $suites = isset($data['suites']) && is_array($data['suites']) ? $data['suites'] : [];

        $this->extractTests(
            suites: $suites,
            tests: $tests,
            stats: $stats,
            hasMultipleProjects: $this->hasMultipleProjects($data),
        );

        return new JsonReportDTO(
            schema: JsonReportDTO::SCHEMA_V1,
            target: $target,
            runId: $runId,
            stats: $this->parseStats($stats),
            tests: $tests,
        );
    }

    /**
     * @param  array<string, int>  $data
     */
    private function parseStats(array $data): JsonReportStatsDTO
    {
        return new JsonReportStatsDTO(
            passed: $data['passed'],
            failed: $data['failed'],
            skipped: $data['skipped'],
            durationMs: $data['durationMs'],
        );
    }

    /**
     * @param  array<int|string, mixed>  $suites
     * @param  list<JsonReportTestDTO>  $tests
     * @param  array{passed:int,failed:int,skipped:int,durationMs:int}  $stats
     */
    private function extractTests(array $suites, array &$tests, array &$stats, bool $hasMultipleProjects, ?string $fallbackFile = null): void
    {
        foreach ($suites as $suite) {
            if (! is_array($suite)) {
                continue;
            }

            $file = is_string($suite['file'] ?? null) ? $suite['file'] : $fallbackFile;

            if (isset($suite['suites']) && is_array($suite['suites'])) {
                $this->extractTests($suite['suites'], $tests, $stats, $hasMultipleProjects, $file);
            }
            if (! isset($suite['specs'])) {
                continue;
            }
            if (! is_array($suite['specs'])) {
                continue;
            }

            foreach ($suite['specs'] as $spec) {
                if (! is_array($spec)) {
                    continue;
                }

                $this->processSpec($spec, $tests, $stats, $hasMultipleProjects, $file);
            }
        }
    }

    /**
     * @param  array<string|int, mixed>  $spec
     * @param  list<JsonReportTestDTO>  $tests
     * @param  array{passed:int,failed:int,skipped:int,durationMs:int}  $stats
     */
    private function processSpec(array $spec, array &$tests, array &$stats, bool $hasMultipleProjects, ?string $file): void
    {
        if (! isset($spec['tests']) || ! is_array($spec['tests'])) {
            return;
        }

        foreach ($spec['tests'] as $test) {
            if (! is_array($test)) {
                continue;
            }
            if (! isset($test['results'])) {
                continue;
            }
            if (! is_array($test['results'])) {
                continue;
            }
            foreach ($test['results'] as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $canonicalTest = $this->transformTest($spec, $test, $result, $hasMultipleProjects, $file);
                $tests[] = $canonicalTest;
                $stats[$canonicalTest->status->value]++;
                $stats['durationMs'] += $canonicalTest->durationMs ?? 0;
            }
        }
    }

    /**
     * @param  array<string|int, mixed>  $spec
     * @param  array<string|int, mixed>  $test
     * @param  array<string|int, mixed>  $result
     */
    private function transformTest(array $spec, array $test, array $result, bool $hasMultipleProjects, ?string $file): JsonReportTestDTO
    {
        $name = is_string($spec['title'] ?? null)
            ? $spec['title']
            : (is_string($test['title'] ?? null) ? $test['title'] : 'unknown test');

        $projectName = is_string($test['projectName'] ?? null) ? $test['projectName'] : null;
        if ($hasMultipleProjects && $projectName !== null && $projectName !== '') {
            $name = "[{$projectName}] {$name}";
        }

        $status = $this->mapStatus($result['status'] ?? null);
        $durationMs = $this->resolveDurationMs($result);
        $error = $this->extractError($status, $result);
        $extraLines = $this->extractExtraLines($result);

        return new JsonReportTestDTO(
            name: $name,
            status: $status,
            file: $file,
            durationMs: $durationMs,
            id: null,
            error: $error,
            artifacts: null,
            extraLines: $extraLines,
        );
    }

    private function mapStatus(mixed $status): TestStatusType
    {
        if (! is_string($status)) {
            return TestStatusType::FAILED;
        }

        return match ($status) {
            'passed' => TestStatusType::PASSED,
            'skipped' => TestStatusType::SKIPPED,
            'failed', 'timedOut', 'interrupted' => TestStatusType::FAILED,
            default => TestStatusType::FAILED,
        };
    }

    /**
     * @param  array<string|int, mixed>  $result
     */
    private function resolveDurationMs(array $result): int
    {
        $duration = $result['duration'] ?? null;
        if (is_int($duration)) {
            return $duration;
        }

        if (is_float($duration)) {
            return (int) round($duration);
        }

        $startTime = $result['startTime'] ?? null;
        $endTime = $result['endTime'] ?? null;

        if (! is_string($startTime) || ! is_string($endTime)) {
            return 0;
        }

        $startTimestamp = strtotime($startTime);
        $endTimestamp = strtotime($endTime);
        if ($startTimestamp === false || $endTimestamp === false) {
            return 0;
        }

        return max(0, ($endTimestamp - $startTimestamp) * 1000);
    }

    /**
     * @param  array<string|int, mixed>  $result
     */
    private function extractError(TestStatusType $status, array $result): ?JsonReportErrorDTO
    {
        if ($status !== TestStatusType::FAILED) {
            return null;
        }

        $errors = $result['errors'] ?? null;
        if (! is_array($errors) || ! isset($errors[0]) || ! is_array($errors[0])) {
            return null;
        }

        $message = is_string($errors[0]['message'] ?? null) && $errors[0]['message'] !== ''
            ? $errors[0]['message']
            : 'Test failed';

        $stack = is_string($errors[0]['stack'] ?? null) ? $errors[0]['stack'] : null;
        if ($stack !== null && $stack !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $stack);
            if (is_array($lines)) {
                $message .= "\n\nStack trace:\n".implode("\n", array_slice($lines, 0, 10));
            }
        }

        return new JsonReportErrorDTO(message: trim($message));
    }

    /**
     * @param  array<string|int, mixed>  $result
     * @return list<string>|null
     */
    private function extractExtraLines(array $result): ?array
    {
        $lines = [];

        foreach (['stdout', 'stderr'] as $stream) {
            $entries = $result[$stream] ?? null;
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $text = $entry['text'] ?? null;
                if (! is_string($text)) {
                    continue;
                }

                $lines[] = preg_replace('/\r?\n$/', '', trim($text)) ?? '';
            }
        }

        return $lines !== [] ? $lines : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasMultipleProjects(array $data): bool
    {
        $config = $data['config'] ?? null;
        if (! is_array($config)) {
            return false;
        }

        $projects = $config['projects'] ?? null;
        if (! is_array($projects)) {
            return false;
        }

        return count($projects) > 1;
    }
}
