<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

/**
 * @internal
 */
interface ParamsFileWriterContract
{
    /**
     * Persist the params JSON and return an absolute file path.
     */
    public function write(string $project, string $runId, string $json): string;
}
