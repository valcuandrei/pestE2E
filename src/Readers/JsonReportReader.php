<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Readers;

use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;
use ValcuAndrei\PestE2E\Parsers\JsonReportParser;

/**
 * @internal
 */
final readonly class JsonReportReader
{
    /**
     * @param  JsonReportParser  $parser  parser
     */
    public function __construct(
        private JsonReportParser $parser,
    ) {}

    /**
     * Read the report for a run.
     */
    public function readForRun(RunContextDTO $context): JsonReportDTO
    {
        $path = str_replace('{runId}', $context->runId, $context->target->reportPath);

        // If path is relative, make it relative to the target directory
        if (! str_starts_with($path, '/')) {
            $path = $context->target->dir.'/'.$path;
        }

        $report = $this->parser->parseFile($path);

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
