<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\Contracts\ParamsFileWriterContract;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Support\CliOptions;

it('builds explicit execution flags even when there are no params', function () {
    $writer = fakeParamsFileWriter();
    $builder = new ProcessPlanBuilder($writer);

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        env: ['APP_URL' => 'http://localhost'],
        params: [],
        artifactsDir: null,
    );

    $ctx = RunContextDTO::make($target, 'run-123');

    $plan = $builder->build($ctx);

    $env = $plan->command->getMergedEnv();

    expect($plan->testFilter)->toBeNull()
        ->and($plan->headed)->toBeFalse()
        ->and($plan->debug)->toBeFalse()
        ->and($plan->commandPreview)->toBe('playwright test --reporter json')
        ->and(isset($env['PEST_E2E_PARAMS']))->toBeFalse()
        ->and(isset($env['PEST_E2E_PARAMS_FILE']))->toBeFalse()
        ->and($plan->hasParams())->toBeFalse();
});

it('includes the resolved report directory in the plan and environment', function () {
    $writer = fakeParamsFileWriter();
    $builder = new ProcessPlanBuilder($writer);

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
    );

    $ctx = RunContextDTO::make($target, 'run-123', reportDirectory: '/tmp/pest-e2e/reports/frontend/run-123');

    $plan = $builder->build($ctx);
    $env = $plan->command->getMergedEnv();

    expect($plan->reportDirectory)->toBe('/tmp/pest-e2e/reports/frontend/run-123')
        ->and($env['PEST_E2E_REPORT_DIR'])->toBe('/tmp/pest-e2e/reports/frontend/run-123');
});

it('uses inline params when JSON is small enough', function () {
    $writer = fakeParamsFileWriter();
    $builder = (new ProcessPlanBuilder($writer))->withMaxInlineBytes(10_000);

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        env: ['APP_URL' => 'http://localhost'],
        params: ['baseUrl' => 'http://localhost'],
        artifactsDir: null,
    );

    $ctx = RunContextDTO::make($target, 'run-abc');

    $plan = $builder->build($ctx);

    $env = $plan->command->getMergedEnv();

    expect($plan->hasParams())->toBeTrue()
        ->and($plan->usesParamsFile())->toBeFalse()
        ->and($env)->toHaveKey('PEST_E2E_PARAMS')
        ->and($env)->not->toHaveKey('PEST_E2E_PARAMS_FILE')
        ->and($writer->lastJson)->toBeNull(); // file writer not used
});

it('uses params file when JSON is too large', function () {
    $writer = fakeParamsFileWriter('/abs/path/params.json');
    $builder = (new ProcessPlanBuilder($writer))->withMaxInlineBytes(10); // force file mode

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        env: ['APP_URL' => 'http://localhost'],
        params: ['baseUrl' => 'http://localhost', 'auth' => ['ticket' => str_repeat('x', 100)]],
        artifactsDir: null,
    );

    $ctx = RunContextDTO::make($target, 'run-big');

    $plan = $builder->build($ctx);

    $env = $plan->command->getMergedEnv();

    expect($plan->hasParams())->toBeTrue()
        ->and($plan->usesParamsFile())->toBeTrue()
        ->and($env['PEST_E2E_PARAMS_FILE'])->toBe('/abs/path/params.json')
        ->and(isset($env['PEST_E2E_PARAMS']))->toBeFalse()
        ->and($writer->lastTarget)->toBe('frontend')
        ->and($writer->lastRunId)->toBe('run-big')
        ->and($writer->lastJson)->toBeString();
});

it('promotes browse and debug into explicit execution flags', function () {
    CliOptions::$browse = true;
    CliOptions::$debug = true;

    try {
        $writer = fakeParamsFileWriter();
        $builder = new ProcessPlanBuilder($writer);
        $target = new TargetConfigDTO(name: 'frontend', dir: 'js');
        $ctx = RunContextDTO::make($target, 'run-filtered', testFilter: 'profile');

        $plan = $builder->build($ctx);

        expect($plan->testFilter)->toBe('profile')
            ->and($plan->headed)->toBeTrue()
            ->and($plan->debug)->toBeTrue()
            ->and($plan->options->timeoutSeconds)->toBe(3600)
            ->and($plan->commandPreview)->toContain('--grep profile')
            ->and($plan->commandPreview)->toContain('--headed')
            ->and($plan->commandPreview)->toContain('--debug');
    } finally {
        CliOptions::$browse = false;
        CliOptions::$debug = false;
    }
});

function fakeParamsFileWriter(string $returnPath = '/tmp/pest-e2e/fake.json'): ParamsFileWriterContract
{
    return new class($returnPath) implements ParamsFileWriterContract
    {
        public ?string $lastTarget = null;

        public ?string $lastRunId = null;

        public ?string $lastJson = null;

        public function __construct(
            private readonly string $returnPath,
        ) {}

        public function write(string $target, string $runId, string $json): string
        {
            $this->lastTarget = $target;
            $this->lastRunId = $runId;
            $this->lastJson = $json;

            return $this->returnPath;
        }
    };
}
