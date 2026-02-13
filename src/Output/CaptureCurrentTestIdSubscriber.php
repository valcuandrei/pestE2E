<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Output;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use ValcuAndrei\PestE2E\Support\CurrentPhpunitTestContext;

/**
 * Captures the current PHPUnit test ID when a test is prepared.
 *
 * @internal
 */
final class CaptureCurrentTestIdSubscriber implements PreparedSubscriber
{
    /**
     * Handle the Test Prepared event.
     */
    public function notify(Prepared $event): void
    {
        $context = $this->resolveContext();
        $context->set($event->test()->id());
    }

    /**
     * Resolve the CurrentPhpunitTestContext from the Laravel container.
     */
    private function resolveContext(): CurrentPhpunitTestContext
    {
        return new CurrentPhpunitTestContext;
    }
}
