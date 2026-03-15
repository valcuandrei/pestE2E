<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\Enums\TestStatusType;
use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;
use ValcuAndrei\PestE2E\Parsers\PlaywrightParser;

it('converts a playwright report to canonical json report dto', function () {
    $parser = new PlaywrightParser;

    $report = $parser->parse(json_encode([
        'config' => [
            'projects' => [['name' => 'chromium'], ['name' => 'firefox']],
        ],
        'suites' => [
            [
                'file' => 'tests/e2e/profile.spec.ts',
                'specs' => [
                    [
                        'title' => 'updates user profile',
                        'tests' => [
                            [
                                'projectName' => 'chromium',
                                'results' => [
                                    [
                                        'status' => 'passed',
                                        'duration' => 120,
                                        'stdout' => [['text' => "profile updated\n"]],
                                        'stderr' => [],
                                    ],
                                ],
                            ],
                            [
                                'projectName' => 'firefox',
                                'results' => [
                                    [
                                        'status' => 'timedOut',
                                        'startTime' => '2026-03-12T10:00:00Z',
                                        'endTime' => '2026-03-12T10:00:01Z',
                                        'errors' => [
                                            [
                                                'message' => 'Expected success toast',
                                                'stack' => "Error line 1\nError line 2",
                                            ],
                                        ],
                                        'stdout' => [],
                                        'stderr' => [['text' => "timeout output\n"]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'suites' => [
                    [
                        'file' => 'tests/e2e/nested.spec.ts',
                        'specs' => [
                            [
                                'title' => 'skips flaky flow',
                                'tests' => [
                                    [
                                        'results' => [
                                            [
                                                'status' => 'skipped',
                                                'duration' => 5,
                                                'stdout' => [],
                                                'stderr' => [],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR), 'frontend', 'run-abc');

    expect($report->schema)->toBe(JsonReportDTO::SCHEMA_V1)
        ->and($report->target)->toBe('frontend')
        ->and($report->runId)->toBe('run-abc')
        ->and($report->stats->passed)->toBe(1)
        ->and($report->stats->failed)->toBe(1)
        ->and($report->stats->skipped)->toBe(1)
        ->and($report->stats->durationMs)->toBe(1125)
        ->and($report->tests)->toHaveCount(3);

    $testsByName = collect($report->tests)->keyBy('name');

    expect($testsByName)->toHaveKeys([
        '[chromium] updates user profile',
        '[firefox] updates user profile',
        'skips flaky flow',
    ]);

    expect($testsByName['[chromium] updates user profile']->status)->toBe(TestStatusType::PASSED)
        ->and($testsByName['[chromium] updates user profile']->file)->toBe('tests/e2e/profile.spec.ts')
        ->and($testsByName['[chromium] updates user profile']->extraLines)->toBe(['profile updated']);

    expect($testsByName['[firefox] updates user profile']->status)->toBe(TestStatusType::FAILED)
        ->and($testsByName['[firefox] updates user profile']->error?->message)->toContain('Expected success toast')
        ->and($testsByName['[firefox] updates user profile']->error?->message)->toContain('Stack trace:')
        ->and($testsByName['[firefox] updates user profile']->extraLines)->toBe(['timeout output']);

    expect($testsByName['skips flaky flow']->status)->toBe(TestStatusType::SKIPPED)
        ->and($testsByName['skips flaky flow']->file)->toBe('tests/e2e/nested.spec.ts')
        ->and($testsByName['skips flaky flow']->extraLines)->toBeNull();
});

it('throws when playwright report json is invalid', function () {
    $parser = new PlaywrightParser;

    expect(fn () => $parser->parse('{ invalid', 'frontend', 'run-abc'))
        ->toThrow(JsonReportParserException::class, 'Invalid JSON');
});
