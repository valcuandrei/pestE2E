<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Creates `.env.testing` from `.env` with SQLite and E2E-friendly overrides.
 */
final class CreateEnvTestingStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return $ctx->plan->setupEnvTesting;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($this->createEnvTestingFile($ctx) === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info('.env.testing created successfully.');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->error('Failed to create .env.testing');
        }

        return StepResult::fail();
    }

    /**
     * {@inheritdoc}
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        if (! $ctx->isQuiet() && InstallProjectProbe::envTestingExists()) {
            $ctx->info('.env.testing already exists.');
        }
    }

    /**
     * Merge `.env` keys with testing overrides; skip if `.env.testing` exists unless `--force`.
     */
    private function createEnvTestingFile(InstallContext $ctx): int
    {
        $path = base_path('.env.testing');
        if (is_file($path) && ! $ctx->force) {
            return Command::SUCCESS;
        }

        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            if (! $ctx->isQuiet()) {
                $ctx->warn('.env file not found. Skipping .env.testing creation. Run php artisan key:generate first.');
            }

            return Command::SUCCESS;
        }

        $content = file_get_contents($envPath);
        if ($content === false) {
            return Command::FAILURE;
        }

        $overrides = [
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://127.0.0.1',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => 'testing',
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'database',
            'PEST_E2E_AUTH_ROUTE_ENABLED' => 'true',
        ];

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return Command::FAILURE;
        }

        $result = [];
        $seen = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $result[] = $line;

                continue;
            }

            if (preg_match('/^([A-Za-z_]\w*)=(.*)$/', $trimmed, $m) === 1) {
                $key = $m[1];
                $seen[$key] = true;
                if (array_key_exists($key, $overrides)) {
                    $result[] = $key.'='.$overrides[$key];

                    continue;
                }
            }

            $result[] = $line;
        }

        foreach ($overrides as $key => $value) {
            if (! isset($seen[$key])) {
                $result[] = $key.'='.$value;
            }
        }

        $output = implode("\n", $result);

        return file_put_contents($path, $output) !== false ? Command::SUCCESS : Command::FAILURE;
    }
}
