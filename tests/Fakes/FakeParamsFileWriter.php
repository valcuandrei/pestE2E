<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Fakes;

use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;

final class FakeParamsFileWriter implements ParamsFileWriterContract
{
    public ?string $lastProject = null;

    public ?string $lastRunId = null;

    public ?string $lastJson = null;

    public function __construct(
        private readonly string $returnPath = '/tmp/pest-e2e/fake.json',
    ) {}

    public function write(string $project, string $runId, string $json): string
    {
        $this->lastProject = $project;
        $this->lastRunId = $runId;
        $this->lastJson = $json;

        return $this->returnPath;
    }
}
