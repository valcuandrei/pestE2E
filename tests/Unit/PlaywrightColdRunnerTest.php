<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\JsRunRequestDTO;
use ValcuAndrei\PestE2E\Runners\Js\PlaywrightColdRunner;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;

it('runs commands through process runner', function () {
    $runner = new PlaywrightColdRunner(new ProcessRunner);

    $result = $runner->run(new JsRunRequestDTO(
        command: 'php -r "echo \'ok\';"',
        workingDirectory: getcwd(),
    ));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->stdout)->toBe('ok')
        ->and($result->stderr)->toBe('')
        ->and($result->durationSeconds)->toBeGreaterThan(0);
});

it('exposes cold-runner capabilities', function () {
    $runner = new PlaywrightColdRunner(new ProcessRunner);
    $capabilities = $runner->capabilities();

    expect($capabilities->supportsPersistentRuntime)->toBeFalse()
        ->and($capabilities->requiresExplicitStart)->toBeFalse();
});
