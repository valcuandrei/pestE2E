<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Fakes;

use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;

final class FakeParamsFileWriter implements ParamsFileWriterContract
{
    public ?string $lastTarget = null;

    public ?string $lastRunId = null;

    public ?string $lastJson = null;

    public function __construct(
        private readonly string $returnPath = '/tmp/pest-e2e/fake.json',
    ) {}

    public function write(string $target, string $runId, string $json): string
    {
        $this->lastTarget = $target;
        $this->lastRunId = $runId;
        $this->lastJson = $json;

        return $this->returnPath;
    }
}
