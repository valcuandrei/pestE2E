<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('throws a readable exception when the json report contains failures', function () {
    $reportDTO = JsonReportDTO::fakeWithFailedTest();
    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app()->instance(JsWorkerContract::class, new class($reportDTO) implements JsWorkerContract
    {
        public function __construct(private readonly JsonReportDTO $report) {}

        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            $specs = [];
            foreach ($this->report->tests as $test) {
                $specs[] = [
                    'title' => $test->name,
                    'tests' => [[
                        'results' => [[
                            'status' => $test->status->value,
                            'duration' => $test->durationMs ?? 1,
                            'errors' => $test->error === null ? [] : [[
                                'message' => $test->error->message,
                                'stack' => $test->error->stack,
                            ]],
                            'stdout' => [],
                            'stderr' => [],
                        ]],
                    ]],
                ];
            }

            return new ProcessResultDTO(
                exitCode: 0,
                stdout: json_encode([
                    'suites' => [[
                        'file' => 'tests/e2e/spec.ts',
                        'specs' => $specs,
                    ]],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationSeconds: 0.01,
            );
        }
    });

    e2e()->target(
        $reportDTO->target,
        fn ($p) => $p->dir(getcwd())
    );

    expect(fn () => e2e($reportDTO->target)->run())
        ->toThrow(RuntimeException::class, 'E2E failures')
        ->and(fn () => e2e($reportDTO->target)->run())
        ->toThrow(RuntimeException::class, 'test');
});
