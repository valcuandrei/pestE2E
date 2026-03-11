<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\JsRunnerContract;
use ValcuAndrei\PestE2E\Runners\Js\PlaywrightColdRunner;
use ValcuAndrei\PestE2E\Runners\Js\PlaywrightWarmRunner;

it('binds js runner contract to playwright cold runner by default', function () {
    config()->set('pest-e2e.js_runner.driver', 'playwright');
    config()->set('pest-e2e.js_runner.mode', 'cold');

    app()->forgetInstance(JsRunnerContract::class);
    $runner = app(JsRunnerContract::class);

    expect($runner)->toBeInstanceOf(PlaywrightColdRunner::class);
});

it('binds js runner contract to playwright warm runner when configured', function () {
    config()->set('pest-e2e.js_runner.driver', 'playwright');
    config()->set('pest-e2e.js_runner.mode', 'warm');

    app()->forgetInstance(JsRunnerContract::class);
    $runner = app(JsRunnerContract::class);

    expect($runner)->toBeInstanceOf(PlaywrightWarmRunner::class);
});
