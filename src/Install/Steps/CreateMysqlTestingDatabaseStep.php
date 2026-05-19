<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Support\Facades\DB;
use ValcuAndrei\PestE2E\Install\EnvTestingConnectionResolver;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Best-effort `CREATE DATABASE IF NOT EXISTS` for Sail/MySQL `.env.testing` defaults.
 */
final class CreateMysqlTestingDatabaseStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        if (! $ctx->plan->setupEnvTesting) {
            return false;
        }

        return EnvTestingConnectionResolver::fromEnvTestingFile() === 'mysql';
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        $values = InstallProjectProbe::parseEnvFile(base_path('.env.testing'));
        $database = $this->unquote($values['DB_DATABASE'] ?? 'testing');

        if ($database === '') {
            return StepResult::ok();
        }

        $safeName = str_replace('`', '', $database);

        try {
            DB::statement('CREATE DATABASE IF NOT EXISTS `'.$safeName.'`');

            if (! $ctx->isQuiet()) {
                $ctx->info('MySQL testing database `'.$safeName.'` is ready.');
            }

            return StepResult::ok();
        } catch (\Throwable $exception) {
            if (! $ctx->isQuiet()) {
                $ctx->warn('Could not create MySQL testing database automatically: '.$exception->getMessage());
            }

            return StepResult::ok();
        }
    }

    private function unquote(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
