<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\DTO\ProcessCommandDTO;
use ValcuAndrei\PestE2E\DTO\ProcessOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\Support\JsPackageManager;
use ValcuAndrei\PestE2E\Workers\Playwright\PlaywrightWorker;

it('passes the process plan report directory to Playwright output', function (): void {
    $plan = new ProcessPlanDTO(
        command: new ProcessCommandDTO(workingDirectory: getcwd()),
        options: new ProcessOptionsDTO,
        reportDirectory: '/tmp/pest-e2e/reports/frontend/run-123',
    );

    $worker = new PlaywrightWorker(new JsPackageManager);
    $method = new ReflectionMethod(PlaywrightWorker::class, 'playwrightTestArgs');
    $method->setAccessible(true);

    $args = $method->invoke($worker, $plan);

    expect($args)->toContain('--output')
        ->and($args[array_search('--output', $args, true) + 1])->toBe('/tmp/pest-e2e/reports/frontend/run-123');
});
