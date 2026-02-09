<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportStatsDTO;
use ValcuAndrei\PestE2E\DTO\JsonReportTestDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Registries\TargetRegistry;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

beforeEach(function () {
    app(E2EOutputStore::class)->flush();
});

it('nests e2e output under the current test name', function () {
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $testName = test()->getPrintableTestCaseMethodName();

    expect($reportPath)->not->toBeFalse();
    $reportDTO = JsonReportDTO::fakeWithPassedTest();
    $reportB64 = base64_encode($reportDTO->toJson());

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        runner: 'Playwright',
        command: getReportCommand($reportPath, $reportB64),
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
        artifactsDir: null,
        filterFlag: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    try {
        e2e($reportDTO->target)->run();

        $entries = app(E2EOutputStore::class)->all();
        $lines = $entries[0]->lines;
        $text = implode("\n", $lines);

        expect($entries)->toHaveCount(1)
            ->and($lines[0])->toBe($testName)
            ->and($lines[1])->toContain('  └─ E2E › '.$reportDTO->target.' (runId '.$reportDTO->runId.')')
            ->and($text)->toContain('✓ '.$reportDTO->getPassedTests()[0]->name)
            ->and($text)->toContain('passed=1 failed=0 skipped=0');
    } finally {
        @unlink($reportPath);
    }
});

it('stores a passed run summary when the target succeeds', function () {
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $reportDTO = JsonReportDTO::fakeWithPassedTest();
    $reportB64 = base64_encode($reportDTO->toJson());

    expect($reportPath)->not->toBeFalse();

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        runner: 'Playwright',
        command: getReportCommand($reportPath, $reportB64),
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
        artifactsDir: null,
        filterFlag: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    try {
        e2e($reportDTO->target)->run();

        $entries = app(E2EOutputStore::class)->all();
        $text = implode("\n", $entries[0]->lines);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->ok)->toBeTrue()
            ->and($entries[0]->runId)->toBe($reportDTO->runId)
            ->and($text)->toContain('✓ '.$reportDTO->getPassedTests()[0]->name)
            ->and($text)->toContain('passed=1 failed=0 skipped=0')
            ->and($text)->toContain($reportDTO->target)
            ->and($text)->toContain($reportDTO->runId);
    } finally {
        @unlink($reportPath);
    }
});

it('stores a failed run summary and rethrows on failures', function () {
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $reportDTO = JsonReportDTO::fakeWithFailedTest();
    $reportB64 = base64_encode($reportDTO->toJson());

    expect($reportPath)->not->toBeFalse();

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        runner: 'Playwright',
        command: getReportCommand($reportPath, $reportB64),
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
        artifactsDir: null,
        filterFlag: null,
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    try {
        expect(fn () => e2e($reportDTO->target)->run())->toThrow(\RuntimeException::class);

        $entries = app(E2EOutputStore::class)->all();
        $text = implode("\n", $entries[0]->lines);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->ok)->toBeFalse()
            ->and($entries[0]->runId)->toBe($reportDTO->runId)
            ->and($text)->toContain('✗ '.$reportDTO->getFailedTests()[0]->name)
            ->and($text)->toContain('failed=1')
            ->and($text)->toContain($reportDTO->target)
            ->and($text)->toContain($reportDTO->runId);
    } finally {
        @unlink($reportPath);
    }
});

it('runs filtered test with only() method', function () {
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $reportDTO = JsonReportDTO::fake()
        ->withStats(JsonReportStatsDTO::fakePassed(1))
        ->withTests([JsonReportTestDTO::fakePassed()->withName('can checkout')]);

    expect($reportPath)->not->toBeFalse();

    file_put_contents($reportPath, $reportDTO->toJson());

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        runner: 'Playwright',
        command: 'echo "Mock test runner with filter" && exit 0',
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
        artifactsDir: null,
        filterFlag: '--grep',
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    try {
        e2e($reportDTO->target)->only('can checkout')->run();

        $entries = app(E2EOutputStore::class)->all();
        $text = implode("\n", $entries[0]->lines);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->ok)->toBeTrue()
            ->and($entries[0]->runId)->toBe($reportDTO->runId)
            ->and($text)->toContain('✓ can checkout')
            ->and($text)->toContain('passed=1 failed=0 skipped=0');
    } finally {
        @unlink($reportPath);
    }
});

it('fails when using only() with failed test', function () {
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $reportDTO = JsonReportDTO::fake()
        ->withStats(JsonReportStatsDTO::fakeFailed(1))
        ->withTests([JsonReportTestDTO::fakeFailed()->withName('can checkout')]);

    expect($reportPath)->not->toBeFalse();

    file_put_contents($reportPath, $reportDTO->toJson());

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        runner: 'Playwright',
        command: 'echo "Mock test runner with filter" && exit 0',
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
        artifactsDir: null,
        filterFlag: '--grep',
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    try {
        expect(fn () => e2e($reportDTO->target)->only('can checkout')->run())
            ->toThrow(RuntimeException::class);

        $entries = app(E2EOutputStore::class)->all();
        $text = implode("\n", $entries[0]->lines);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->ok)->toBeFalse()
            ->and($text)->toContain('✗ can checkout')
            ->and($text)->toContain('failed=1');
    } finally {
        @unlink($reportPath);
    }
});

it('runTest() is equivalent to only()->run()', function () {
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');
    $reportDTO = JsonReportDTO::fake()
        ->withStats(JsonReportStatsDTO::fakePassed(1))
        ->withTests([JsonReportTestDTO::fakePassed()->withName('can checkout')]);

    expect($reportPath)->not->toBeFalse();

    file_put_contents($reportPath, $reportDTO->toJson());

    $target = new TargetConfigDTO(
        name: $reportDTO->target,
        dir: getcwd(),
        runner: 'Playwright',
        command: 'echo "Mock test runner with filter" && exit 0',
        reportType: 'json',
        reportPath: $reportPath,
        env: [],
        params: [],
        artifactsDir: null,
        filterFlag: '--grep',
    );

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator($reportDTO->runId));
    app(TargetRegistry::class)->put($target);

    try {
        e2e($reportDTO->target)->runTest('can checkout');

        $entries = app(E2EOutputStore::class)->all();
        $text = implode("\n", $entries[0]->lines);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->ok)->toBeTrue()
            ->and($text)->toContain('✓ can checkout')
            ->and($text)->toContain('passed=1 failed=0 skipped=0');
    } finally {
        @unlink($reportPath);
    }
});

function getReportCommand(string $reportPath, string $reportB64): string
{
    return 'php -r "file_put_contents('
        .var_export($reportPath, true)
        .', base64_decode('
        .var_export($reportB64, true)
        .'));"';
}
