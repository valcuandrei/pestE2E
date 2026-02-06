<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;

it('runs a command and captures stdout', function () {
    $runner = new ProcessRunner;

    $cmd = new ProcessCommandDTO(
        command: 'php -r "echo \'hello\';"',
        workingDirectory: getcwd(),
        env: [],
        injectedEnv: [],
    );

    $plan = new ProcessPlanDTO(
        command: $cmd,
        options: new ProcessOptionsDTO,
    );

    $result = $runner->run($plan);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->stdout)->toBe('hello')
        ->and($result->stderr)->toBe('')
        ->and($result->durationSeconds)->toBeGreaterThan(0);
});

it('captures non-zero exit codes and stderr', function () {
    $runner = new ProcessRunner;

    $cmd = new ProcessCommandDTO(
        command: 'php -r "fwrite(STDERR, \'nope\'); exit(7);"',
        workingDirectory: getcwd(),
    );

    $plan = new ProcessPlanDTO(
        command: $cmd,
        options: new ProcessOptionsDTO,
    );

    $result = $runner->run($plan);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->exitCode)->toBe(7)
        ->and($result->stdout)->toBe('')
        ->and($result->stderr)->toBe('nope');
});

it('merges env with injected env winning', function () {
    $runner = new ProcessRunner;

    $cmd = (new ProcessCommandDTO(
        command: 'php -r "echo getenv(\'FOO\');"',
        workingDirectory: getcwd(),
        env: ['FOO' => 'base'],
    ))->withInjectedEnv(['FOO' => 'injected']);

    $plan = new ProcessPlanDTO(
        command: $cmd,
        options: new ProcessOptionsDTO,
    );

    $result = $runner->run($plan);

    expect($result->stdout)->toBe('injected');
});
