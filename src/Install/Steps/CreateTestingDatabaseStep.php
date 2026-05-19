<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\EnvTestingConnectionResolver;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Ensures `database/testing.sqlite` exists for SQLite-backed tests.
 */
final class CreateTestingDatabaseStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return $ctx->plan->setupTestingDatabase;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($this->createTestingSqlite($ctx) === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info('database/testing.sqlite created successfully.');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->error('Failed to create database/testing.sqlite');
        }

        return StepResult::fail();
    }

    /**
     * {@inheritdoc}
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        if ($ctx->isQuiet()) {
            return;
        }

        if (EnvTestingConnectionResolver::fromEnvTestingFile() !== 'sqlite'
            && EnvTestingConnectionResolver::fromEnvTestingFile() !== ''
            && ! InstallProjectProbe::testingDatabaseExists()) {
            return;
        }

        if (InstallProjectProbe::testingDatabaseExists()) {
            $ctx->info('database/testing.sqlite already exists.');
        }
    }

    /**
     * Create parent `database/` directory if needed and `touch` the SQLite file.
     */
    private function createTestingSqlite(InstallContext $ctx): int
    {
        $path = base_path('database/testing.sqlite');
        if (is_file($path) && ! $ctx->force) {
            return Command::SUCCESS;
        }

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return Command::FAILURE;
        }

        return touch($path) ? Command::SUCCESS : Command::FAILURE;
    }
}
