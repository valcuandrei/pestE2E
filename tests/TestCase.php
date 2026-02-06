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

    protected function getEnvironmentSetUp($app): void
    {
        // Required for sessions/cookies/encryption in auth bridge tests
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');

        // Make sure we can use the session guard
        $app['config']->set('session.driver', 'array');
        $app['config']->set('session.encrypt', false);

        // Helps avoid CSRF surprises for JSON posts in tests
        $app['config']->set('app.env', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();
    }
}
