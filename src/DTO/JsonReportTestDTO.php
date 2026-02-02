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
}
