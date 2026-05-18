<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

use function Laravel\Prompts\confirm;

/**
 * Creates or updates `.env.testing` with parallel-safe E2E overrides.
 */
final class CreateEnvTestingStep extends InstallStep
{
    /** @var array<string, string> */
    private const SAIL_MYSQL_DEFAULTS = [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'mysql',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'testing',
        'DB_USERNAME' => 'sail',
        'DB_PASSWORD' => 'password',
    ];

    /** @var array<string, string> */
    private const PARALLEL_SAFE_TESTING_DEFAULTS = [
        'SESSION_DRIVER' => 'array',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ];

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
        $path = base_path('.env.testing');
        $existed = is_file($path);

        if ($this->writeEnvTestingFile($ctx, $existed) === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info($existed ? '.env.testing updated successfully.' : '.env.testing created successfully.');
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
     * Merge environment keys with parallel-safe testing overrides.
     */
    private function writeEnvTestingFile(InstallContext $ctx, bool $existed): int
    {
        $path = base_path('.env.testing');
        $sourcePath = $existed ? $path : base_path('.env');
        $content = '';

        if (is_file($sourcePath)) {
            $read = file_get_contents($sourcePath);
            if ($read === false) {
                return Command::FAILURE;
            }

            $content = $read;
        } elseif (! $existed && ! $ctx->isQuiet()) {
            $ctx->warn('.env file not found. Creating .env.testing from Pest E2E testing defaults.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return Command::FAILURE;
        }

        $values = $this->readEnvValues($lines);
        $overrides = $this->testingOverrides($ctx, $values, $existed);

        $output = $this->mergeEnvLines($lines, $overrides);

        return file_put_contents($path, $output) !== false ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function testingOverrides(InstallContext $ctx, array $values, bool $existed): array
    {
        $overrides = [
            'APP_ENV' => 'testing',
            'APP_URL' => $values['APP_URL'] ?? 'http://127.0.0.1',
            'PEST_E2E_AUTH_ROUTE_ENABLED' => 'true',
            ...self::PARALLEL_SAFE_TESTING_DEFAULTS,
        ];

        if (! $existed) {
            return [
                ...$overrides,
                ...self::SAIL_MYSQL_DEFAULTS,
            ];
        }

        $connection = strtolower($this->unquote($values['DB_CONNECTION'] ?? ''));

        if ($connection === '') {
            return [
                ...$overrides,
                ...self::SAIL_MYSQL_DEFAULTS,
            ];
        }

        if ($connection === 'sqlite') {
            if (! $ctx->isQuiet()) {
                $ctx->warn('DB_CONNECTION=sqlite is not recommended for parallel browser testing. Laravel parallel testing works best with a real test database so workers can use suffixed databases.');
            }

            if ($this->shouldSwitchSqliteToSailMysql($ctx)) {
                if (! $ctx->isQuiet()) {
                    $ctx->info('Switching .env.testing to Sail-compatible MySQL testing defaults.');
                }

                return [
                    ...$overrides,
                    ...self::SAIL_MYSQL_DEFAULTS,
                ];
            }

            if (! $ctx->isQuiet()) {
                $ctx->warn('Leaving DB_CONNECTION=sqlite unchanged. Re-run with --yes, --force, or switch to a real test database before using parallel E2E tests.');
            }
        }

        return $overrides;
    }

    private function shouldSwitchSqliteToSailMysql(InstallContext $ctx): bool
    {
        if ($ctx->force || (bool) $ctx->option('yes') || (bool) $ctx->option('unattended')) {
            return true;
        }

        if (! $ctx->isInteractive()) {
            return false;
        }

        return confirm(
            'Switch .env.testing from SQLite to Sail MySQL defaults for parallel testing?',
            false
        );
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function readEnvValues(array $lines): array
    {
        $values = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Za-z_]\w*)=(.*)$/', $line, $matches) === 1) {
                $values[$matches[1]] = $matches[2];
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string>  $overrides
     */
    private function mergeEnvLines(array $lines, array $overrides): string
    {
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

        return rtrim(implode("\n", $result), "\n")."\n";
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
