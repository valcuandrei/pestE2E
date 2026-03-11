<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Output;

use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use ValcuAndrei\PestE2E\Runners\ServerRunner;

/**
 * Stops shared E2E servers when the PHPUnit suite finishes.
 *
 * @internal
 */
final class StopSharedServerSubscriber implements FinishedSubscriber
{
    /**
     * Handle the TestSuite Finished event.
     */
    public function notify(Finished $event): void
    {
        ServerRunner::stopAll();
    }
}
