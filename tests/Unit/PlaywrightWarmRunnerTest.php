<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\JsRunRequestDTO;
use ValcuAndrei\PestE2E\Runners\Js\PlaywrightColdRunner;
use ValcuAndrei\PestE2E\Runners\Js\PlaywrightWarmRunner;
use ValcuAndrei\PestE2E\Runners\ProcessRunner;

it('reports warm runner capabilities', function () {
    $runner = new PlaywrightWarmRunner(new PlaywrightColdRunner(new ProcessRunner));
    $capabilities = $runner->capabilities();

    expect($capabilities->supportsPersistentRuntime)->toBeTrue()
        ->and($capabilities->requiresExplicitStart)->toBeTrue();
});

it('captures ws endpoint on start when provided', function () {
    putenv('PEST_E2E_WARM_WS_ENDPOINT=ws://localhost:3000/devtools/browser/abc');
    $runner = new PlaywrightWarmRunner(new PlaywrightColdRunner(new ProcessRunner));

    $runner->start();

    expect($runner->isRunning())->toBeTrue()
        ->and($runner->wsEndpoint())->toBe('ws://localhost:3000/devtools/browser/abc');

    $runner->stop();
    putenv('PEST_E2E_WARM_WS_ENDPOINT');
});

it('runs requests through the warm runner', function () {
    putenv('PEST_E2E_WARM_WS_ENDPOINT=ws://localhost:3999/devtools/browser/test');
    $runner = new PlaywrightWarmRunner(new PlaywrightColdRunner(new ProcessRunner));

    $result = $runner->run(new JsRunRequestDTO(
        command: 'php -r "echo getenv(\'PEST_E2E_WARM_WS_ENDPOINT\');"',
        workingDirectory: getcwd(),
    ));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->stdout)->toBe('ws://localhost:3999/devtools/browser/test');

    $runner->stop();
    putenv('PEST_E2E_WARM_WS_ENDPOINT');
});
