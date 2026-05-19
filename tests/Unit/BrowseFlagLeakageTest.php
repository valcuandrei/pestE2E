<?php

declare(strict_types=1);

use Pest\Plugins\Parallel;
use ValcuAndrei\PestE2E\Builders\ProcessPlanBuilder;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\RunContextDTO;
use ValcuAndrei\PestE2E\DTO\TargetConfigDTO;
use ValcuAndrei\PestE2E\Support\AgentOutputIntent;
use ValcuAndrei\PestE2E\Support\AgentParallelMode;
use ValcuAndrei\PestE2E\Support\ArtisanTestArgvBridge;
use ValcuAndrei\PestE2E\Support\CliOptions;
use ValcuAndrei\PestE2E\Support\TempParamsFileWriter;

beforeEach(function (): void {
    foreach ([
        'PEST_E2E_BROWSE',
        'PEST_E2E_DEBUG',
        'PEST_E2E_AGENT_OUTPUT',
    ] as $key) {
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    CliOptions::$browse = false;
    CliOptions::$debug = false;
    CliOptions::$parallel = false;

    AgentOutputIntent::clear();
});

afterEach(function (): void {
    AgentOutputIntent::clear();
});

it('enables headed mode when --browse is passed on the first run', function (): void {
    $argv = ['artisan', 'test', 'tests/Browser', '--browse'];

    ArtisanTestArgvBridge::apply($argv);
    CliOptions::fromArguments($argv);

    expect(CliOptions::$browse)->toBeTrue();

    $plan = (new ProcessPlanBuilder(new TempParamsFileWriter))->build(RunContextDTO::make(
        new TargetConfigDTO(name: 'frontend', dir: '/tmp/e2e'),
        'run-browse',
    ));

    expect($plan->headed)->toBeTrue();
});

it('runs headless on a second run without --browse after a browse run', function (): void {
    $browseArgv = ['artisan', 'test', 'tests/Browser', '--browse'];
    ArtisanTestArgvBridge::apply($browseArgv);
    CliOptions::fromArguments($browseArgv);

    expect(CliOptions::$browse)->toBeTrue();

    $headlessArgv = ['artisan', 'test', 'tests/Browser'];
    ArtisanTestArgvBridge::apply($headlessArgv);
    CliOptions::fromArguments($headlessArgv);

    expect(CliOptions::$browse)->toBeFalse()
        ->and(getenv('PEST_E2E_BROWSE'))->toBeFalse();

    $plan = (new ProcessPlanBuilder(new TempParamsFileWriter))->build(RunContextDTO::make(
        new TargetConfigDTO(name: 'frontend', dir: '/tmp/e2e'),
        'run-headless',
    ));

    expect($plan->headed)->toBeFalse()
        ->and($plan->debug)->toBeFalse();
});

it('does not re-enable browse from a stale agent intent file', function (): void {
    $directory = storage_path('framework/testing/pest-e2e-agent-output');
    $path = $directory.'/.agent-intent.json';

    if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create intent dir: {$directory}");
    }

    file_put_contents($path, json_encode([
        'PEST_E2E_BROWSE' => '1',
        'PEST_E2E_DEBUG' => '1',
        'PEST_E2E_AGENT_OUTPUT' => '1',
    ], JSON_THROW_ON_ERROR));

    $argv = ['artisan', 'test', 'tests/Browser'];
    ArtisanTestArgvBridge::apply($argv);

    AgentOutputIntent::hydrateEnvironment();
    CliOptions::fromArguments($argv);

    expect(CliOptions::$browse)->toBeFalse()
        ->and(CliOptions::$debug)->toBeFalse()
        ->and(CliOptions::agentOutput())->toBeTrue();
});

it('does not persist browse or debug in the agent intent file', function (): void {
    putenv('PEST_E2E_BROWSE=1');
    putenv('PEST_E2E_DEBUG=1');
    putenv('PEST_E2E_AGENT_OUTPUT=1');
    $_SERVER['PEST_E2E_BROWSE'] = '1';
    $_SERVER['PEST_E2E_DEBUG'] = '1';
    $_SERVER['PEST_E2E_AGENT_OUTPUT'] = '1';

    AgentOutputIntent::persistFromEnvironment();

    $path = storage_path('framework/testing/pest-e2e-agent-output/.agent-intent.json');
    $contents = is_file($path) ? file_get_contents($path) : false;

    expect($contents)->toBeString();

    $decoded = json_decode($contents, true);

    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKey('PEST_E2E_AGENT_OUTPUT')
        ->and($decoded)->not->toHaveKey('PEST_E2E_BROWSE')
        ->and($decoded)->not->toHaveKey('PEST_E2E_DEBUG');
});

it('runs parallel workers headless without --browse after a previous browse run', function (): void {
    $browseArgv = ['artisan', 'test', 'tests/Browser', '--parallel', '--browse'];
    ArtisanTestArgvBridge::apply($browseArgv);
    CliOptions::fromArguments($browseArgv);

    expect(CliOptions::$browse)->toBeTrue();

    if (class_exists(Parallel::class)) {
        Parallel::setGlobal(AgentParallelMode::BROWSE_GLOBAL_KEY, '1');
    }

    unset($_SERVER['PEST_E2E_BROWSE'], $_ENV['PEST_E2E_BROWSE']);
    putenv('PEST_E2E_BROWSE');

    $workerArgv = ['vendor/bin/pest', '--parallel', 'tests/Browser'];
    CliOptions::fromArguments($workerArgv);

    expect(CliOptions::$browse)->toBeTrue();

    if (class_exists(Parallel::class)) {
        unset($_ENV['PEST_PARALLEL_GLOBAL_'.AgentParallelMode::BROWSE_GLOBAL_KEY]);
        putenv('PEST_PARALLEL_GLOBAL_'.AgentParallelMode::BROWSE_GLOBAL_KEY);
    }

    $headlessArgv = ['artisan', 'test', 'tests/Browser', '--parallel'];
    ArtisanTestArgvBridge::apply($headlessArgv);
    CliOptions::fromArguments($headlessArgv);

    expect(CliOptions::$browse)->toBeFalse();

    $plan = (new ProcessPlanBuilder(new TempParamsFileWriter))->build(
        RunContextDTO::make(new TargetConfigDTO(name: 'frontend', dir: '/tmp/e2e'), 'run-parallel-headless'),
        new ProcessOptionsDTO(timeoutSeconds: 30),
    );

    expect($plan->headed)->toBeFalse();
});

it('does not treat stale runtime report directories as browse intent', function (): void {
    $reportDir = sys_get_temp_dir().'/pest-e2e/reports/frontend/stale-browse-run';
    $marker = $reportDir.'/.pest-e2e-run';

    if (! is_dir($reportDir) && ! @mkdir($reportDir, 0775, true) && ! is_dir($reportDir)) {
        throw new RuntimeException("Unable to create report dir: {$reportDir}");
    }

    file_put_contents($marker, '1');

    $argv = ['artisan', 'test', 'tests/Browser'];
    ArtisanTestArgvBridge::apply($argv);
    CliOptions::fromArguments($argv);

    $plan = (new ProcessPlanBuilder(new TempParamsFileWriter))->build(RunContextDTO::make(
        new TargetConfigDTO(name: 'frontend', dir: '/tmp/e2e'),
        'fresh-run',
        reportDirectory: $reportDir,
    ));

    expect(CliOptions::$browse)->toBeFalse()
        ->and($plan->headed)->toBeFalse();

    @unlink($marker);
    @rmdir($reportDir);
});
