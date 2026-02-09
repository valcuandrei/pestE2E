<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * @internal
 */
final readonly class JsonReportStatsDTO
{
    public function __construct(
        public int $passed,
        public int $failed,
        public int $skipped,
        public int $durationMs,
    ) {}

    /**
     * Create a new JsonReportStatsDTO instance with the given passed.
     */
    public function withPassed(int $passed): self
    {
        return new self(
            passed: $passed,
            failed: $this->failed,
            skipped: $this->skipped,
            durationMs: $this->durationMs,
        );
    }

    /**
     * Create a new JsonReportStatsDTO instance with the given failed.
     */
    public function withFailed(int $failed): self
    {
        return new self(
            passed: $this->passed,
            failed: $failed,
            skipped: $this->skipped,
            durationMs: $this->durationMs,
        );
    }

    /**
     * Create a new JsonReportStatsDTO instance with the given skipped.
     */
    public function withSkipped(int $skipped): self
    {
        return new self(
            passed: $this->passed,
            failed: $this->failed,
            skipped: $skipped,
            durationMs: $this->durationMs,
        );
    }

    /**
     * Create a new JsonReportStatsDTO instance with the given durationMs.
     */
    public function withDurationMs(int $durationMs): self
    {
        return new self(
            passed: $this->passed,
            failed: $this->failed,
            skipped: $this->skipped,
            durationMs: $durationMs,
        );
    }

    /**
     * Check if the report is successful.
     */
    public function isSuccessful(): bool
    {
        return ! $this->isFailed();
    }

    /**
     * Check if the report is failed.
     */
    public function isFailed(): bool
    {
        return $this->failed > 0;
    }

    /**
     * Get the stats as an array.
     *
     * @return array{
     *  passed:int,
     *  failed:int,
     *  skipped:int,
     *  durationMs:int,
     * }
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'durationMs' => $this->durationMs,
        ];
    }

    /**
     * Get the stats as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Create a new JsonReportStatsDTO instance from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data));

        return self::fromArray($data);
    }

    /**
     * Create a new JsonReportStatsDTO instance from an array.
     */
    public static function fromArray(mixed $array): self
    {
        assert(is_array($array));
        assert(array_key_exists('passed', $array));
        assert(array_key_exists('failed', $array));
        assert(array_key_exists('skipped', $array));
        assert(array_key_exists('durationMs', $array));
        assert(is_int($array['passed']));
        assert(is_int($array['failed']));
        assert(is_int($array['skipped']));
        assert(is_int($array['durationMs']));

        return new self(
            passed: $array['passed'],
            failed: $array['failed'],
            skipped: $array['skipped'],
            durationMs: $array['durationMs'],
        );
    }

    /**
     * Create a new JsonReportStatsDTO instance with the given passed, failed, skipped and durationMs.
     */
    public static function fake(): self
    {
        return new self(
            passed: 0,
            failed: 0,
            skipped: 0,
            durationMs: 0,
        );
    }

    /**
     * Create a new JsonReportStatsDTO instance with the given passed.
     */
    public static function fakePassed(int $passed = 1): self
    {
        return self::fake()->withPassed($passed);
    }

    /**
     * Create a new JsonReportStatsDTO instance with the given failed.
     */
    public static function fakeFailed(int $failed = 1): self
    {
        return self::fake()->withFailed($failed);
    }

    /**
     * Create a new JsonReportStatsDTO instance with the given skipped.
     */
    public static function fakeSkipped(int $skipped = 1): self
    {
        return self::fake()->withSkipped($skipped);
    }
}
