<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

use ValcuAndrei\PestE2E\Enums\TestStatusType;

/**
 * @internal
 */
final readonly class JsonReportTestDTO
{
    /**
     * @param  string  $name  test name
     * @param  TestStatusType  $status  test status
     * @param  string|null  $file  (optional) file path
     * @param  int|null  $durationMs  (optional) duration in milliseconds
     * @param  string|null  $id  (optional) test ID
     * @param  JsonReportErrorDTO|null  $error  (optional) error
     * @param  JsonReportArtifactsDTO|null  $artifacts  (optional) artifacts
     */
    public function __construct(
        public string $name,
        public TestStatusType $status,
        public ?string $file = null,
        public ?int $durationMs = null,
        public ?string $id = null,
        public ?JsonReportErrorDTO $error = null,
        public ?JsonReportArtifactsDTO $artifacts = null,
    ) {}

    /**
     * Create a new JsonReportTestDTO instance with the given name.
     */
    public function withName(string $name): self
    {
        return new self(
            name: $name,
            status: $this->status,
            file: $this->file,
            durationMs: $this->durationMs,
            id: $this->id,
            error: $this->error,
            artifacts: $this->artifacts,
        );
    }

    /**
     * Create a new JsonReportTestDTO instance with the given status.
     */
    public function withStatus(TestStatusType $status): self
    {
        return new self(
            name: $this->name,
            status: $status,
            file: $this->file,
            durationMs: $this->durationMs,
            id: $this->id,
            error: $this->error,
            artifacts: $this->artifacts,
        );
    }

    /**
     * Create a new JsonReportTestDTO instance with the given file.
     */
    public function withFile(string $file): self
    {
        return new self(
            name: $this->name,
            status: $this->status,
            file: $file,
            durationMs: $this->durationMs,
            id: $this->id,
            error: $this->error,
            artifacts: $this->artifacts,
        );
    }

    /**
     * Create a new JsonReportTestDTO instance with the given durationMs.
     */
    public function withDurationMs(int $durationMs): self
    {
        return new self(
            name: $this->name,
            status: $this->status,
            file: $this->file,
            durationMs: $durationMs,
            id: $this->id,
            error: $this->error,
            artifacts: $this->artifacts,
        );
    }

    /**
     * Create a new JsonReportTestDTO instance with the given id.
     */
    public function withId(string $id): self
    {
        return new self(
            name: $this->name,
            status: $this->status,
            file: $this->file,
            durationMs: $this->durationMs,
            id: $id,
            error: $this->error,
            artifacts: $this->artifacts,
        );
    }

    /**
     * Create a new JsonReportTestDTO instance with the given error.
     */
    public function withError(JsonReportErrorDTO $error): self
    {
        return new self(
            name: $this->name,
            status: $this->status,
            file: $this->file,
            durationMs: $this->durationMs,
            id: $this->id,
            error: $error,
            artifacts: $this->artifacts,
        );
    }

    /**
     * Create a new JsonReportTestDTO instance with the given artifacts.
     */
    public function withArtifacts(JsonReportArtifactsDTO $artifacts): self
    {
        return new self(
            name: $this->name,
            status: $this->status,
            file: $this->file,
            durationMs: $this->durationMs,
            id: $this->id,
            error: $this->error,
            artifacts: $artifacts,
        );
    }

    /**
     * Check if the test failed.
     */
    public function isFailed(): bool
    {
        return $this->status === TestStatusType::FAILED;
    }

    /**
     * Check if the test passed.
     */
    public function isPassed(): bool
    {
        return $this->status === TestStatusType::PASSED;
    }

    /**
     * Check if the test was skipped.
     */
    public function isSkipped(): bool
    {
        return $this->status === TestStatusType::SKIPPED;
    }

    /**
     * Get the test as an array.
     *
     * @return array{
     *  name:string,
     *  status:string,
     *  file:string|null,
     *  durationMs:int|null,
     *  id:string|null,
     *  error:array{
     *   message:string,
     *   stack:string|null,
     *  }|null,
     *  artifacts:array{
     *   trace:string|null,
     *   video:string|null,
     *   screenshots:list<string>,
     *  }|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'file' => $this->file,
            'durationMs' => $this->durationMs,
            'id' => $this->id,
            'error' => $this->error?->toArray(),
            'artifacts' => $this->artifacts?->toArray(),
        ];
    }

    /**
     * Get the test as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Create a new JsonReportTestDTO instance from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data));

        return self::fromArray($data);
    }

    /**
     * Create a new JsonReportTestDTO instance from an array.
     */
    public static function fromArray(mixed $array): self
    {
        assert(is_array($array));
        assert(array_key_exists('name', $array));
        assert(array_key_exists('status', $array));
        assert(is_string($array['name']));
        assert(is_string($array['status']));

        $file = $array['file'] ?? null;
        assert(is_string($file) || $file === null);

        $durationMs = $array['durationMs'] ?? null;
        assert(is_int($durationMs) || $durationMs === null);

        $id = $array['id'] ?? null;
        assert(is_string($id) || $id === null);

        $status = TestStatusType::tryFrom($array['status']);

        if ($status === null) {
            throw new \InvalidArgumentException("Invalid status: {$array['status']}");
        }

        return new self(
            name: $array['name'],
            status: $status,
            file: $file,
            durationMs: $durationMs,
            id: $id,
            error: isset($array['error']) && $array['error'] ? JsonReportErrorDTO::fromArray($array['error']) : null,
            artifacts: isset($array['artifacts']) && $array['artifacts'] ? JsonReportArtifactsDTO::fromArray($array['artifacts']) : null,
        );
    }

    /**
     * Create a new JsonReportTestDTO instance with the given name, status, file, durationMs, id, error and artifacts.
     */
    public static function fake(): self
    {
        return new self(
            name: 'test',
            status: TestStatusType::PASSED,
            file: null,
            durationMs: 1000,
        );
    }

    /**
     * Create a new JsonReportTestDTO instance with the given name, status, file, durationMs, id, error and artifacts.
     */
    public static function fakePassed(): self
    {
        return self::fake()->withStatus(TestStatusType::PASSED);
    }

    /**
     * Create a new JsonReportTestDTO instance with the given name, status, file, durationMs, id, error and artifacts.
     */
    public static function fakeFailed(): self
    {
        return self::fake()
            ->withStatus(TestStatusType::FAILED)
            ->withError(JsonReportErrorDTO::fake())
            ->withArtifacts(JsonReportArtifactsDTO::fake());
    }

    /**
     * Create a new JsonReportTestDTO instance with the given name, status, file, durationMs, id, error and artifacts.
     */
    public static function fakeSkipped(): self
    {
        return self::fake()->withStatus(TestStatusType::SKIPPED);
    }
}
