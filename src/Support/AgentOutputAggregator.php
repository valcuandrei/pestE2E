<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;

/**
 * Collects agent JSON summaries from parallel workers into the coordinator process.
 *
 * @internal
 */
final class AgentOutputAggregator
{
    private const ENABLED_MARKER = '.enabled';

    public static function prepareRun(): void
    {
        $directory = self::runDirectory();

        self::clearDirectory($directory);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }

        @chmod($directory, 0777);

        $marker = $directory.'/'.self::ENABLED_MARKER;

        file_put_contents($marker, '1');
        @chmod($marker, 0666);
    }

    public static function hasActiveRun(): bool
    {
        return is_file(self::runDirectory().'/'.self::ENABLED_MARKER);
    }

    public static function record(E2EOutputEntryDTO $entry): void
    {
        if (! self::hasActiveRun()) {
            return;
        }

        $directory = self::runDirectory();

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }

        @chmod($directory, 0777);

        $file = $directory.'/worker-'.self::workerSegment().'.jsonl';

        file_put_contents(
            $file,
            json_encode(self::serializeEntry($entry), JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * @return array<int, E2EOutputEntryDTO>
     */
    public static function collect(): array
    {
        $directory = self::runDirectory();

        if (! is_dir($directory)) {
            return [];
        }

        $files = glob($directory.'/worker-*.jsonl');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $entries = [];

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $payload = json_decode($line, true);

                if (! is_array($payload)) {
                    continue;
                }

                $normalizedPayload = self::normalizePayload($payload);

                if ($normalizedPayload === null) {
                    continue;
                }

                $entries[] = self::deserializeEntry($normalizedPayload);
            }
        }

        return $entries;
    }

    public static function cleanup(): void
    {
        self::clearDirectory(self::runDirectory());
    }

    private static function runDirectory(): string
    {
        $laravelPath = self::laravelStoragePath();

        if ($laravelPath !== null) {
            return $laravelPath;
        }

        $cwd = getcwd();

        if ($cwd !== false && is_dir($cwd.'/storage')) {
            return $cwd.'/storage/framework/testing/pest-e2e-agent-output';
        }

        return rtrim(sys_get_temp_dir(), '/').'/pest-e2e-agent-output';
    }

    private static function laravelStoragePath(): ?string
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            return app()->storagePath('framework/testing/pest-e2e-agent-output');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function normalizePayload(array $payload): ?array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private static function workerSegment(): string
    {
        $token = ParallelWorker::token();

        if ($token === null || $token === '') {
            return '0';
        }

        return preg_replace('/[^A-Za-z0-9_.-]+/', '-', $token) ?: '0';
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeEntry(E2EOutputEntryDTO $entry): array
    {
        return [
            'type' => $entry->type,
            'target' => $entry->target,
            'runId' => $entry->runId,
            'ok' => $entry->ok,
            'durationSeconds' => $entry->durationSeconds,
            'stats' => $entry->stats?->toArray(),
            'reportDirectory' => $entry->reportDirectory,
            'phpTestFile' => $entry->phpTestFile,
            'phpTestName' => $entry->phpTestName,
            'failures' => $entry->failures,
            'errorMessage' => $entry->errorMessage,
            'errorStack' => $entry->errorStack,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function deserializeEntry(array $payload): E2EOutputEntryDTO
    {
        $stats = null;

        if (isset($payload['stats']) && is_array($payload['stats'])) {
            $stats = JsonReportStatsDTO::fromArray($payload['stats']);
        }

        return new E2EOutputEntryDTO(
            type: is_string($payload['type'] ?? null) ? $payload['type'] : 'run',
            target: is_string($payload['target'] ?? null) ? $payload['target'] : 'unknown',
            runId: is_string($payload['runId'] ?? null) ? $payload['runId'] : 'unknown',
            ok: (bool) ($payload['ok'] ?? false),
            durationSeconds: is_numeric($payload['durationSeconds'] ?? null)
                ? (float) $payload['durationSeconds']
                : null,
            stats: $stats,
            lines: [],
            reportDirectory: is_string($payload['reportDirectory'] ?? null) ? $payload['reportDirectory'] : null,
            phpTestFile: is_string($payload['phpTestFile'] ?? null) ? $payload['phpTestFile'] : null,
            phpTestName: is_string($payload['phpTestName'] ?? null) ? $payload['phpTestName'] : null,
            failures: is_array($payload['failures'] ?? null) ? self::normalizeFailures($payload['failures']) : [],
            errorMessage: is_string($payload['errorMessage'] ?? null) ? $payload['errorMessage'] : null,
            errorStack: is_string($payload['errorStack'] ?? null) ? $payload['errorStack'] : null,
        );
    }

    /**
     * @param  array<mixed>  $failures
     * @return array<int, array{name: string, js_file: ?string, message: ?string, stack: ?string}>
     */
    private static function normalizeFailures(array $failures): array
    {
        $normalized = [];

        foreach ($failures as $failure) {
            if (! is_array($failure)) {
                continue;
            }

            $name = $failure['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }
            if ($name === '') {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'js_file' => is_string($failure['js_file'] ?? null) && $failure['js_file'] !== ''
                    ? $failure['js_file']
                    : null,
                'message' => is_string($failure['message'] ?? null) && $failure['message'] !== ''
                    ? $failure['message']
                    : null,
                'stack' => is_string($failure['stack'] ?? null) && $failure['stack'] !== ''
                    ? $failure['stack']
                    : null,
            ];
        }

        return $normalized;
    }

    private static function clearDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }
            if ($entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;

            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
