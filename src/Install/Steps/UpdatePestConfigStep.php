<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Updates `tests/Pest.php` with Feature `RefreshDatabase` and Browser `DatabaseMigrations` + E2ETestCase.
 */
final class UpdatePestConfigStep extends InstallStep
{
    private const REFRESH_DATABASE = 'RefreshDatabase';

    private const REFRESH_DATABASE_FQCN = RefreshDatabase::class;

    private const DATABASE_MIGRATIONS = 'DatabaseMigrations';

    private const DATABASE_MIGRATIONS_FQCN = DatabaseMigrations::class;

    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        if (! $ctx->plan->updatePestConfig) {
            return false;
        }
        if (! $ctx->pestPhpHasE2ETestCase()) {
            return true;
        }

        return ! $ctx->pestPhpHasFeatureRefreshDatabase();
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($this->applyPestConfigUpdate($ctx) === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info('Pest config updated successfully.');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->error('Failed to update pest config');
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

        if ($ctx->pestPhpHasE2ETestCase()) {
            $ctx->info('Pest config already includes E2ETestCase.');
        }

        if ($ctx->pestPhpHasFeatureRefreshDatabase()) {
            $ctx->info('Pest config already includes RefreshDatabase for Feature tests.');
        }
    }

    /**
     * Rewrite `tests/Pest.php`; persists the file and refreshes the in-memory Pest cache on success.
     */
    private function applyPestConfigUpdate(InstallContext $ctx): int
    {
        $pest = $ctx->getPestPhp();

        if (! is_string($pest)) {
            return Command::FAILURE;
        }

        $pest = $this->ensureFeatureRefreshDatabase($pest);

        if (! $ctx->pestPhpHasE2ETestCase()) {
            $pest = $this->ensureImport($pest, self::DATABASE_MIGRATIONS_FQCN);
            $pest = preg_replace('/\?>\s*$/', '', $pest) ?? $pest;

            $pest .= "\n\npest()->extend(Tests\\E2ETestCase::class)\n"
                .'    ->use('.self::DATABASE_MIGRATIONS."::class)\n"
                ."    ->in('Browser');\n";
        }

        if (file_put_contents($ctx->pestPhpPath(), $pest) === false) {
            return Command::FAILURE;
        }

        $ctx->pestPhp = $pest;

        return Command::SUCCESS;
    }

    private function ensureFeatureRefreshDatabase(string $pest): string
    {
        if ($this->featureUsesRefreshDatabase($pest)) {
            return $pest;
        }

        $pest = $this->ensureImport($pest, self::REFRESH_DATABASE_FQCN);

        $refreshClass = $this->traitClassReference($pest, self::REFRESH_DATABASE);

        if (preg_match('/pest\(\)->extend\([^)]+\)\s*->in\([\'"]Feature[\'"]\)/s', $pest) === 1) {
            return preg_replace(
                '/(pest\(\)->extend\([^)]+\))\s*->in\([\'"]Feature[\'"]\)/s',
                '$1'."\n    ->use({$refreshClass})\n    ->in('Feature')",
                $pest,
                1
            ) ?? $pest;
        }

        if (preg_match('/uses\s*\(\s*([^)]*)\)\s*->in\([\'"]Feature[\'"]\)/s', $pest, $matches) === 1) {
            $classes = trim($matches[1]);
            if (! str_contains($classes, self::REFRESH_DATABASE)) {
                $replacement = $classes === ''
                    ? $refreshClass
                    : $classes.",\n    ".self::REFRESH_DATABASE_FQCN.'::class';

                return preg_replace(
                    '/uses\s*\(\s*([^)]*)\)\s*->in\([\'"]Feature[\'"]\)/s',
                    "uses(\n    {$replacement},\n)->in('Feature')",
                    $pest,
                    1
                ) ?? $pest;
            }

            return $pest;
        }

        $testCaseClass = $this->testCaseClassReference($pest);
        $featureBlock = "\n\npest()->extend({$testCaseClass})\n    ->use({$refreshClass})\n    ->in('Feature');\n";

        if (preg_match('/\npest\(\)->extend\(Tests\\\\E2ETestCase::class\)/', $pest) === 1) {
            return preg_replace(
                '/(\npest\(\)->extend\(Tests\\\\E2ETestCase::class\))/',
                $featureBlock.'$1',
                $pest,
                1
            ) ?? $pest.$featureBlock;
        }

        return rtrim($pest).$featureBlock;
    }

    private function featureUsesRefreshDatabase(string $pest): bool
    {
        if (preg_match('/uses\s*\(\s*(?:.|\n)*?'.self::REFRESH_DATABASE.'(?:.|\n)*?\)\s*->in\([\'"]Feature[\'"]\)/s', $pest) === 1) {
            return true;
        }

        return preg_match('/pest\(\)->extend\((?:.|\n)*?'.self::REFRESH_DATABASE.'(?:.|\n)*?->in\([\'"]Feature[\'"]\)/s', $pest) === 1;
    }

    private function ensureImport(string $pest, string $fqcn): string
    {
        if (str_contains($pest, 'use '.$fqcn.';')) {
            return $pest;
        }

        if (preg_match('/^<\?php\s+declare\(strict_types=1\);\s*/', $pest) === 1) {
            return preg_replace(
                '/^<\?php\s+declare\(strict_types=1\);\s*/',
                "<?php\n\ndeclare(strict_types=1);\n\nuse {$fqcn};\n\n",
                $pest,
                1
            ) ?? $pest;
        }

        return preg_replace(
            '/^<\?php\s*/',
            "<?php\n\nuse {$fqcn};\n\n",
            $pest,
            1
        ) ?? $pest;
    }

    private function traitClassReference(string $pest, string $shortName): string
    {
        if (str_contains($pest, 'use '.self::REFRESH_DATABASE_FQCN.';') && $shortName === self::REFRESH_DATABASE) {
            return self::REFRESH_DATABASE.'::class';
        }

        if (str_contains($pest, 'use '.self::DATABASE_MIGRATIONS_FQCN.';') && $shortName === self::DATABASE_MIGRATIONS) {
            return self::DATABASE_MIGRATIONS.'::class';
        }

        return match ($shortName) {
            self::REFRESH_DATABASE => self::REFRESH_DATABASE_FQCN.'::class',
            self::DATABASE_MIGRATIONS => self::DATABASE_MIGRATIONS_FQCN.'::class',
            default => $shortName.'::class',
        };
    }

    private function testCaseClassReference(string $pest): string
    {
        if (preg_match('/use\s+Tests\\\\TestCase\s*;/', $pest) === 1) {
            return 'TestCase::class';
        }

        return 'Tests\\TestCase::class';
    }
}
