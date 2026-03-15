<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Workers\Playwright\PlaywrightWorker;

it('playwright worker implements js worker contract', function () {
    expect(app(JsWorkerContract::class))->toBeInstanceOf(PlaywrightWorker::class);
});
