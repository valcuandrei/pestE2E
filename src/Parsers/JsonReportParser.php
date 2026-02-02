<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Parsers;

use JsonException;
use ValcuAndrei\PestE2E\DTO\JsonReportArtifactsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportErrorDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\Enums\TestStatusType;
use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;

/**
 * @internal
 */
final class JsonReportParser
{
    public const SCHEMA_V1 = 'pest-e2e.v1';

    /**
     * Parse a JSON report file.
     */
    public function parseFile(string $path): JsonReportDTO
    {
        if (! is_file($path)) {
            throw new JsonReportParserException("JSON report file not found: {$path}");
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new JsonReportParserException("Unable to read JSON report file: {$path}");
        }

        return $this->parseJson($raw, $path);
    }

    /**
     * Parse a JSON report.
     *
     * @param  string  $source  (optional) source of the JSON
     */
    public function parseJson(string $json, string $source = '<inline>'): JsonReportDTO
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new JsonReportParserException("Invalid JSON in report ({$source}): {$e->getMessage()}", previous: $e);
        }

        if (! is_array($data)) {
            throw new JsonReportParserException("Invalid JSON report root ({$source}): expected object");
        }

        /** @var array<string, mixed> $data */
        $schema = $this->requireString($data, 'schema', $source);

        if ($schema !== self::SCHEMA_V1) {
            throw new JsonReportParserException("Unsupported JSON report schema ({$source}): {$schema}");
        }

        $project = $this->requireString($data, 'project', $source);
        $runId = $this->requireString($data, 'runId', $source);

        /** @var array<string, mixed> $statsRaw */
        $statsRaw = $this->requireArray($data, 'stats', $source);

        $stats = new JsonReportStatsDTO(
            passed: $this->requireInt($statsRaw, 'passed', $source.'.stats'),
            failed: $this->requireInt($statsRaw, 'failed', $source.'.stats'),
            skipped: $this->requireInt($statsRaw, 'skipped', $source.'.stats'),
            durationMs: $this->requireInt($statsRaw, 'durationMs', $source.'.stats'),
        );

        $tests = [];

        if (array_key_exists('tests', $data)) {
            $testsRaw = $data['tests'];

            if (! is_array($testsRaw)) {
                throw new JsonReportParserException("Invalid tests field ({$source}): expected array");
            }

            foreach ($testsRaw as $i => $t) {
                if (! is_array($t)) {
                    throw new JsonReportParserException("Invalid test entry ({$source}.tests[{$i}]): expected object");
                }

                /** @var array<string, mixed> $t */
                $tests[] = $this->parseTest($t, "{$source}.tests[{$i}]");
            }
        }

        return new JsonReportDTO(
            schema: $schema,
            project: $project,
            runId: $runId,
            stats: $stats,
            tests: $tests,
        );
    }

    /**
     * Parse a test from the report.
     *
     * @param  array<string, mixed>  $t
     */
    private function parseTest(array $t, string $source): JsonReportTestDTO
    {
        $name = $this->requireString($t, 'name', $source);
        $statusRaw = $this->requireString($t, 'status', $source);
        $status = TestStatusType::tryFrom($statusRaw);

        if ($status === null) {
            throw new JsonReportParserException("Invalid status ({$source}): {$statusRaw}");
        }

        $file = $this->optionalString($t, 'file');
        $id = $this->optionalString($t, 'id');
        $durationMs = $this->optionalInt($t, 'durationMs');
        $error = null;

        if (array_key_exists('error', $t) && $t['error'] !== null) {
            if (! is_array($t['error'])) {
                throw new JsonReportParserException("Invalid error field ({$source}.error): expected object");
            }
            /** @var array<string, mixed> $e */
            $e = $t['error'];

            $error = new JsonReportErrorDTO(
                message: $this->requireString($e, 'message', $source.'.error'),
                stack: $this->optionalString($e, 'stack'),
            );
        }

        $artifacts = null;

        if (array_key_exists('artifacts', $t) && $t['artifacts'] !== null) {
            if (! is_array($t['artifacts'])) {
                throw new JsonReportParserException("Invalid artifacts field ({$source}.artifacts): expected object");
            }
            /** @var array<string, mixed> $a */
            $a = $t['artifacts'];
            $screenshots = [];

            if (array_key_exists('screenshots', $a) && $a['screenshots'] !== null) {
                if (! is_array($a['screenshots'])) {
                    throw new JsonReportParserException("Invalid screenshots field ({$source}.artifacts.screenshots): expected array");
                }

                foreach ($a['screenshots'] as $j => $s) {
                    if (! is_string($s)) {
                        throw new JsonReportParserException("Invalid screenshot entry ({$source}.artifacts.screenshots[{$j}]): expected string");
                    }

                    $screenshots[] = $s;
                }
            }

            $artifacts = new JsonReportArtifactsDTO(
                trace: $this->optionalString($a, 'trace'),
                video: $this->optionalString($a, 'video'),
                screenshots: $screenshots,
            );
        }

        return new JsonReportTestDTO(
            name: $name,
            status: $status,
            file: $file,
            durationMs: $durationMs,
            id: $id,
            error: $error,
            artifacts: $artifacts,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireString(array $data, string $key, string $source): string
    {
        if (! array_key_exists($key, $data) || ! is_string($data[$key]) || $data[$key] === '') {
            throw new JsonReportParserException("Missing/invalid string field ({$source}): {$key}");
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function requireArray(array $data, string $key, string $source): array
    {
        if (! array_key_exists($key, $data) || ! is_array($data[$key])) {
            throw new JsonReportParserException("Missing/invalid object field ({$source}): {$key}");
        }

        /** @var array<string, mixed> $arr */
        $arr = $data[$key];

        return $arr;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireInt(array $data, string $key, string $source): int
    {
        if (! array_key_exists($key, $data) || ! is_int($data[$key])) {
            throw new JsonReportParserException("Missing/invalid int field ({$source}): {$key}");
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optionalString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optionalInt(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return is_int($data[$key]) ? $data[$key] : null;
    }
}
