<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Output;

use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use ValcuAndrei\PestE2E\Runners\ServerRunner;
use ValcuAndrei\PestE2E\Support\WarmBrowserManager;

/**
 * Stops shared E2E servers and warm-browser servers when the PHPUnit suite
 * finishes. Runs once per PHPUnit process, so under `--parallel` each
 * ParaTest worker's Finished event fires here for its own subprocess.
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
        WarmBrowserManager::stopAll();
    }
}
