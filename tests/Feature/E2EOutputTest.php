<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

beforeEach(function () {
    $store = app(E2EOutputStore::class);
    $store->flush();
    $store->flushPerTestEntries();
});

it('nests e2e output under the current test name', function () {
    $testName = test()->getPrintableTestCaseMethodName();

    $reportDTO = JsonReportDTO::fakeWithPassedTest();
    bindWorkerReturningReport($reportDTO);

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        env: [],
        params: [],
        artifactsDir: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    e2e($reportDTO->target)->run();

    $entries = allPerTestEntries();
    $lines = $entries[0]->lines;
    $text = implode("\n", $lines);
    $plainText = normalizeFormattedOutput($text);
    $branchPrefix = E2EOutputFormatter::BASE_INDENT.E2EOutputFormatter::BRANCH_PREFIX;

    expect($entries)->toHaveCount(1)
        ->and($lines[0])->toBe($testName)
        ->and($lines[1])->toContain($branchPrefix.'E2E › '.$reportDTO->target.' (runId '.$reportDTO->runId.')')
        ->and($plainText)->toContain('✓ '.$reportDTO->getPassedTests()[0]->name)
        ->and($plainText)->toContain('passed=1 failed=0 skipped=0');
});

it('stores a passed run summary when the target succeeds', function () {
    $reportDTO = JsonReportDTO::fakeWithPassedTest();
    bindWorkerReturningReport($reportDTO);

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        env: [],
        params: [],
        artifactsDir: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    e2e($reportDTO->target)->run();

    $entries = allPerTestEntries();
    $text = normalizeFormattedOutput(implode("\n", $entries[0]->lines));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->ok)->toBeTrue()
        ->and($entries[0]->runId)->toBe($reportDTO->runId)
        ->and($text)->toContain('✓ '.$reportDTO->getPassedTests()[0]->name)
        ->and($text)->toContain('passed=1 failed=0 skipped=0')
        ->and($text)->toContain($reportDTO->target)
        ->and($text)->toContain($reportDTO->runId);
});

it('stores a failed run summary and rethrows on failures', function () {
    $reportDTO = JsonReportDTO::fakeWithFailedTest();
    bindWorkerReturningReport($reportDTO);

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        env: [],
        params: [],
        artifactsDir: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    expect(fn () => e2e($reportDTO->target)->run())->toThrow(\RuntimeException::class);

    $entries = allPerTestEntries();
    $text = normalizeFormattedOutput(implode("\n", $entries[0]->lines));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->ok)->toBeFalse()
        ->and($entries[0]->runId)->toBe($reportDTO->runId)
        ->and($text)->toContain('✗ '.$reportDTO->getFailedTests()[0]->name)
        ->and($text)->toContain('failed=1')
        ->and($text)->toContain($reportDTO->target)
        ->and($text)->toContain($reportDTO->runId);
});

it('runs filtered test with only() method', function () {
    $reportDTO = JsonReportDTO::fake()
        ->withStats(JsonReportStatsDTO::fakePassed(1))
        ->withTests([JsonReportTestDTO::fakePassed()->withName('can checkout')]);
    bindWorkerReturningReport($reportDTO, function (ProcessPlanDTO $plan): void {
        expect($plan->testFilter)->toBe('can checkout');
    });

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        env: [],
        params: [],
        artifactsDir: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    e2e($reportDTO->target)->only('can checkout')->run();

    $entries = allPerTestEntries();
    $text = normalizeFormattedOutput(implode("\n", $entries[0]->lines));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->ok)->toBeTrue()
        ->and($entries[0]->runId)->toBe($reportDTO->runId)
        ->and($text)->toContain('✓ can checkout')
        ->and($text)->toContain('passed=1 failed=0 skipped=0');
});

it('fails when using only() with failed test', function () {
    $reportDTO = JsonReportDTO::fake()
        ->withStats(JsonReportStatsDTO::fakeFailed(1))
        ->withTests([JsonReportTestDTO::fakeFailed()->withName('can checkout')]);
    bindWorkerReturningReport($reportDTO, function (ProcessPlanDTO $plan): void {
        expect($plan->testFilter)->toBe('can checkout');
    });

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        env: [],
        params: [],
        artifactsDir: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    expect(fn () => e2e($reportDTO->target)->only('can checkout')->run())
        ->toThrow(RuntimeException::class);

    $entries = allPerTestEntries();
    $text = normalizeFormattedOutput(implode("\n", $entries[0]->lines));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->ok)->toBeFalse()
        ->and($text)->toContain('✗ can checkout')
        ->and($text)->toContain('failed=1');
});

it('runTest() is equivalent to only()->run()', function () {
    $reportDTO = JsonReportDTO::fake()
        ->withStats(JsonReportStatsDTO::fakePassed(1))
        ->withTests([JsonReportTestDTO::fakePassed()->withName('can checkout')]);
    bindWorkerReturningReport($reportDTO, function (ProcessPlanDTO $plan): void {
        expect($plan->testFilter)->toBe('can checkout');
    });

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        env: [],
        params: [],
        artifactsDir: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    e2e($reportDTO->target)->runTest('can checkout');

    $entries = allPerTestEntries();
    $text = normalizeFormattedOutput(implode("\n", $entries[0]->lines));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->ok)->toBeTrue()
        ->and($text)->toContain('✓ can checkout')
        ->and($text)->toContain('passed=1 failed=0 skipped=0');
});

/**
 * @param  callable(ProcessPlanDTO):void|null  $assertPlan
 */
function bindWorkerReturningReport(JsonReportDTO $report, ?callable $assertPlan = null): void
{
    app()->instance(JsWorkerContract::class, new class($report, $assertPlan) implements JsWorkerContract
    {
        /**
         * @param  callable(ProcessPlanDTO):void|null  $assertPlan
         */
        public function __construct(private readonly JsonReportDTO $report, private readonly mixed $assertPlan) {}

        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            if (is_callable($this->assertPlan)) {
                ($this->assertPlan)($plan);
            }

            return new ProcessResultDTO(
                exitCode: 0,
                stdout: toPlaywrightJson($this->report),
                stderr: '',
                durationSeconds: 0.05,
            );
        }
    });
}

function toPlaywrightJson(JsonReportDTO $report): string
{
    $specs = [];

    foreach ($report->tests as $test) {
        $result = [
            'status' => $test->status->value,
            'duration' => $test->durationMs ?? 1,
            'stdout' => [],
            'stderr' => [],
        ];

        if ($test->error !== null) {
            $result['errors'] = [[
                'message' => $test->error->message,
                'stack' => $test->error->stack,
            ]];
        }

        $specs[] = [
            'title' => $test->name,
            'tests' => [[
                'results' => [$result],
            ]],
        ];
    }

    return json_encode([
        'suites' => [[
            'file' => 'tests/e2e/spec.ts',
            'specs' => $specs,
        ]],
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return array<int, \ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO>
 */
function allPerTestEntries(): array
{
    $store = app(E2EOutputStore::class);

    $perTest = $store->getAllPerTestEntries();
    if ($perTest !== []) {
        return $perTest[array_key_first($perTest)] ?? [];
    }

    return $store->all();
}

function normalizeFormattedOutput(string $text): string
{
    $withoutTags = strip_tags($text);

    return (string) preg_replace('/\e\[[0-9;]*m/', '', $withoutTags);
}
