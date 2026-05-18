<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

use ValcuAndrei\PestE2E\DTO\JsonReportDTO;

interface JsonParserContract
{
    /**
     * Parse a JSON string into a JsonReportDTO.
     */
    public function parse(string $json, string $target, string $runId): JsonReportDTO;
}
