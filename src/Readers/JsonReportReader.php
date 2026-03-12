<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Readers;

use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;
use ValcuAndrei\PestE2E\Contracts\JsonParserContract;

/**
 * @internal
 */
final readonly class JsonReportReader
{
    /**
     * @param  JsonParserContract  $parser  parser
     */
    public function __construct(
        private JsonParserContract $parser,
    ) {}

    /**
     * Read the report for a run.
     */
    public function readForRun(RunContextDTO $context, string $stdout): JsonReportDTO
    {
        $report = $this->parser->parse($stdout, $context->target->name, $context->runId);

        if ($report->target !== $context->target->name) {
            throw new JsonReportParserException(
                "JSON report target mismatch: expected {$context->target->name}, got {$report->target}"
            );
        }

        if ($report->runId !== $context->runId) {
            throw new JsonReportParserException(
                "JSON report runId mismatch: expected {$context->runId}, got {$report->runId}"
            );
        }

        return $report;
    }
}
