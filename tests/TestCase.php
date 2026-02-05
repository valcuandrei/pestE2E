<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use ValcuAndrei\PestE2E\PestE2EServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PestE2EServiceProvider::class];
    }
}
