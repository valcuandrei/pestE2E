<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\JsRunnerContract;
use ValcuAndrei\PestE2E\Runners\Js\PlaywrightColdRunner;

it('binds js runner contract to playwright cold runner by default', function () {
    $runner = app(JsRunnerContract::class);

    expect($runner)->toBeInstanceOf(PlaywrightColdRunner::class);
});
