<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Tests\Fakes\FakeParamsFileWriter;

it('injects target and run id even when there are no params', function () {
    $writer = new FakeParamsFileWriter;
    $builder = new ProcessPlanBuilder($writer);

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: 'reports/report.json',
        env: ['APP_URL' => 'http://localhost'],
        params: [],
        artifactsDir: null,
    );

    $ctx = RunContextDTO::make($target, 'run-123');

    $plan = $builder->build($ctx);

    $env = $plan->command->getMergedEnv();

    expect($env['PEST_E2E_TARGET'])->toBe('frontend')
        ->and($env['PEST_E2E_RUN_ID'])->toBe('run-123')
        ->and(isset($env['PEST_E2E_PARAMS']))->toBeFalse()
        ->and(isset($env['PEST_E2E_PARAMS_FILE']))->toBeFalse()
        ->and($plan->hasParams())->toBeFalse();
});

it('uses inline params when JSON is small enough', function () {
    $writer = new FakeParamsFileWriter;
    $builder = (new ProcessPlanBuilder($writer))->withMaxInlineBytes(10_000);

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: 'reports/report.json',
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
    $writer = new FakeParamsFileWriter('/abs/path/params.json');
    $builder = (new ProcessPlanBuilder($writer))->withMaxInlineBytes(10); // force file mode

    $target = new TargetConfigDTO(
        name: 'frontend',
        dir: 'js',
        command: 'npx playwright test',
        reportType: 'json',
        reportPath: 'reports/report.json',
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
