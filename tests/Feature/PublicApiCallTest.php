<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('passes merged env and params to the worker plan', function () {
    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator('run-123'));
    app()->instance(JsWorkerContract::class, new class implements JsWorkerContract
    {
        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            expect($plan->command->getMergedEnv())->toMatchArray([
                'FROM_TARGET' => '1',
                'FROM_HANDLE' => '1',
                'PEST_E2E_TARGET' => 'frontend',
                'PEST_E2E_RUN_ID' => 'run-123',
            ]);
            expect($plan->params)->not->toBeNull();
            expect($plan->params?->params)->toMatchArray([
                'fromTarget' => 'yes',
                'fromHandle' => 'yes',
            ]);

            return new ProcessResultDTO(
                exitCode: 0,
                stdout: JsonReportDTO::fakeWithPassedTest()
                    ->withTarget('frontend')
                    ->withRunId('run-123')
                    ->toJson(),
                stderr: '',
                durationSeconds: 0.01,
            );
        }
    });

    e2e()->target('frontend', fn ($p) => $p
        ->dir(getcwd())
        ->env(['FROM_TARGET' => '1'])
        ->params(['fromTarget' => 'yes'])
    );

    e2e('frontend')
        ->withEnv(['FROM_HANDLE' => '1'])
        ->withParams(['fromHandle' => 'yes'])
        ->run();
});

it('passes only() filter into the worker plan', function () {
    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator('run-123'));
    app()->instance(JsWorkerContract::class, new class implements JsWorkerContract
    {
        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            expect($plan->testFilter)->toBe('checkout');

            return new ProcessResultDTO(
                exitCode: 0,
                stdout: JsonReportDTO::fakeWithPassedTest()
                    ->withTarget('frontend')
                    ->withRunId('run-123')
                    ->toJson(),
                stderr: '',
                durationSeconds: 0.01,
            );
        }
    });

    e2e()->target('frontend', fn ($p) => $p->dir(getcwd()));

    e2e('frontend')->only('checkout')->run();
});

it('uses configured report base directory for the worker plan', function () {
    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator('run-123'));
    app()->instance(JsWorkerContract::class, new class implements JsWorkerContract
    {
        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            expect($plan->reportDirectory)->toBeString()
                ->and($plan->reportDirectory)->toEndWith('/frontend/run-123');
            expect($plan->command->getMergedEnv())->toHaveKey('PEST_E2E_REPORT_DIR');

            return new ProcessResultDTO(
                exitCode: 0,
                stdout: JsonReportDTO::fakeWithPassedTest()
                    ->withTarget('frontend')
                    ->withRunId('run-123')
                    ->toJson(),
                stderr: '',
                durationSeconds: 0.01,
            );
        }
    });

    $baseReportDir = sys_get_temp_dir().'/pest-e2e-api-report-dir-'.uniqid();
    config()->set('pest-e2e.reports.base_dir', $baseReportDir);

    try {
        e2e()->target('frontend', fn ($p) => $p->dir(getcwd()));

        e2e('frontend')->run();

        expect(is_dir($baseReportDir.'/frontend/run-123'))->toBeTrue();
    } finally {
        removeDirectory($baseReportDir);
    }
});

function removeDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }

    @rmdir($dir);
}
