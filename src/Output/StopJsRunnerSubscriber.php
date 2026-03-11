<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Output;

use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use ValcuAndrei\PestE2E\Contracts\JsRunnerContract;

/**
 * Stops the configured JS runner when the PHPUnit suite finishes.
 *
 * @internal
 */
final class StopJsRunnerSubscriber implements FinishedSubscriber
{
    /**
     * Handle the TestSuite Finished event.
     */
    public function notify(Finished $event): void
    {
        if (! function_exists('app')) {
            return;
        }

        if (! app()->bound(JsRunnerContract::class)) {
            return;
        }

        $runner = app(JsRunnerContract::class);
        $runner->stop();
    }
}
