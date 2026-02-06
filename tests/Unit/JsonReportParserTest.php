<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;
use ValcuAndrei\PestE2E\Parsers\JsonReportParser;

it('parses a valid report file', function () {
    $parser = new JsonReportParser;

    $report = $parser->parseFile(__DIR__.'/../Fixtures/report.valid.json');

    expect($report->schema)->toBe(JsonReportParser::SCHEMA_V1)
        ->and($report->target)->toBe('frontend')
        ->and($report->runId)->toBe('run-123')
        ->and($report->stats->passed)->toBe(1)
        ->and($report->stats->failed)->toBe(1)
        ->and(count($report->tests))->toBe(2)
        ->and($report->hasFailures())->toBeTrue();

    $failed = $report->getFailedTests();
    expect(count($failed))->toBe(1)
        ->and($failed[0]->name)->toBe('can checkout')
        ->and($failed[0]->error?->message)->toBe('expected visible')
        ->and($failed[0]->artifacts?->trace)->toContain('trace.zip');
});

it('throws on unsupported schema', function () {
    $parser = new JsonReportParser;

    $fn = fn () => $parser->parseFile(__DIR__.'/../Fixtures/report.bad-schema.json');

    expect($fn)->toThrow(JsonReportParserException::class, 'Unsupported JSON report schema');
});

it('throws on invalid json', function () {
    $parser = new JsonReportParser;

    $fn = fn () => $parser->parseJson('{ nope', '<inline>');

    expect($fn)->toThrow(JsonReportParserException::class, 'Invalid JSON');
});
