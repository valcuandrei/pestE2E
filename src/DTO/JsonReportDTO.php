<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

use ValcuAndrei\PestE2E\Parsers\JsonReportParser;

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
        public string $target,
        public string $runId,
        public JsonReportStatsDTO $stats,
        public array $tests = [],
    ) {}

    /**
     * Create a new JsonReportDTO instance with the given schema.
     */
    public function withSchema(string $schema): self
    {
        return new self(
            schema: $schema,
            target: $this->target,
            runId: $this->runId,
            stats: $this->stats,
            tests: $this->tests,
        );
    }

    /**
     * Create a new JsonReportDTO instance with the given target.
     */
    public function withTarget(string $target): self
    {
        return new self(
            schema: $this->schema,
            target: $target,
            runId: $this->runId,
            stats: $this->stats,
            tests: $this->tests,
        );
    }

    /**
     * Create a new JsonReportDTO instance with the given runId.
     */
    public function withRunId(string $runId): self
    {
        return new self(
            schema: $this->schema,
            target: $this->target,
            runId: $runId,
            stats: $this->stats,
            tests: $this->tests,
        );
    }

    /**
     * Create a new JsonReportDTO instance with the given stats.
     */
    public function withStats(JsonReportStatsDTO $stats): self
    {
        return new self(
            schema: $this->schema,
            target: $this->target,
            runId: $this->runId,
            stats: $stats,
            tests: $this->tests,
        );
    }

    /**
     * Create a new JsonReportDTO instance with the given tests.
     *
     * @param  array<int, JsonReportTestDTO>  $tests
     */
    public function withTests(array $tests): self
    {
        return new self(
            schema: $this->schema,
            target: $this->target,
            runId: $this->runId,
            stats: $this->stats,
            tests: $tests,
        );
    }

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
     * @return list<JsonReportTestDTO>
     */
    public function getFailedTests(): array
    {
        return array_values(array_filter(
            $this->tests,
            static fn (JsonReportTestDTO $t): bool => $t->isFailed()
        ));
    }

    /**
     * Get the passed tests from the report.
     *
     * @return list<JsonReportTestDTO>
     */
    public function getPassedTests(): array
    {
        return array_values(array_filter(
            $this->tests,
            static fn (JsonReportTestDTO $t): bool => $t->isPassed()
        ));
    }

    /**
     * Get the skipped tests from the report.
     *
     * @return list<JsonReportTestDTO>
     */
    public function getSkippedTests(): array
    {
        return array_values(array_filter(
            $this->tests,
            static fn (JsonReportTestDTO $t): bool => $t->isSkipped()
        ));
    }

    /**
     * Get the tests from the report.
     *
     * @return list<JsonReportTestDTO>
     */
    public function getTests(): array
    {
        return array_values($this->tests);
    }

    /**
     * Get the report as an array.
     *
     * @return array{
     *  schema:string,
     *  target:string,
     *  runId:string,
     *  stats:array{
     *   passed:int,
     *   failed:int,
     *   skipped:int,
     *   durationMs:int,
     *  },
     *  tests:list<array{
     *   name:string,
     *   status:string,
     *   file:string|null,
     *   durationMs:int|null,
     *   id:string|null,
     *   error:array{
     *    message:string,
     *    stack:string|null,
     *   }|null,
     *   artifacts:array{
     *    trace:string|null,
     *    video:string|null,
     *    screenshots:list<string>,
     *   }|null,
     *  }>,
     * }
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'target' => $this->target,
            'runId' => $this->runId,
            'stats' => $this->stats->toArray(),
            'tests' => array_values(array_map(static fn (JsonReportTestDTO $t): array => $t->toArray(), $this->tests)),
        ];
    }

    /**
     * Get the report as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Create a new JsonReportDTO instance from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data));

        return self::fromArray($data);
    }

    /**
     * Create a new JsonReportDTO instance from an array.
     */
    public static function fromArray(mixed $array): self
    {
        assert(is_array($array));
        assert(array_key_exists('schema', $array));
        assert(array_key_exists('target', $array));
        assert(array_key_exists('runId', $array));
        assert(array_key_exists('stats', $array));
        assert(array_key_exists('tests', $array));
        assert(is_array($array['tests']));
        assert(is_string($array['schema']));
        assert(is_string($array['target']));
        assert(is_string($array['runId']));

        return new self(
            schema: $array['schema'],
            target: $array['target'],
            runId: $array['runId'],
            stats: JsonReportStatsDTO::fromArray($array['stats']),
            tests: array_values(array_map(JsonReportTestDTO::fromArray(...), $array['tests'])),
        );
    }

    /**
     * Create a new JsonReportDTO instance with the given schema, target, runId, stats and tests.
     */
    public static function fake(): self
    {
        return new self(
            schema: JsonReportParser::SCHEMA_V1,
            target: 'target',
            runId: 'runId',
            stats: JsonReportStatsDTO::fake(),
            tests: [JsonReportTestDTO::fake()],
        );
    }

    /**
     * Create a new JsonReportDTO instance with a passed test.
     */
    public static function fakeWithPassedTest(): self
    {
        return self::fake()
            ->withStats(JsonReportStatsDTO::fakePassed(1))
            ->withTests([JsonReportTestDTO::fakePassed()]);
    }

    /**
     * Create a new JsonReportDTO instance with a failed test.
     */
    public static function fakeWithFailedTest(): self
    {
        return self::fake()
            ->withStats(JsonReportStatsDTO::fakeFailed(1))
            ->withTests([JsonReportTestDTO::fakeFailed()]);
    }

    /**
     * Create a new JsonReportDTO instance with a skipped test.
     */
    public static function fakeWithSkippedTest(): self
    {
        return self::fake()
            ->withStats(JsonReportStatsDTO::fakeSkipped(1))
            ->withTests([JsonReportTestDTO::fakeSkipped()]);
    }
}
