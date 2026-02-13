<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\PHPUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ValcuAndrei\PestE2E\Output\CaptureCurrentTestIdSubscriber;

/**
 * PHPUnit Extension that registers subscribers for inline E2E output.
 *
 * CaptureCurrentTestIdSubscriber sets the current test ID on Test Prepared
 * so E2ETargetHandle can store results with putForTest() and the Collision
 * Events hook can print them inline after each test line.
 *
 * @internal
 */
final class PestE2EPhpunitExtension implements Extension
{
    /**
     * Bootstrap the extension.
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new CaptureCurrentTestIdSubscriber);
    }
}
