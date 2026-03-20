<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Appends Pest `pest()->extend(E2ETestCase)` for the Browser directory and ensures `DatabaseMigrations` import.
 */
final class UpdatePestConfigStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return ! $ctx->pestPhpHasE2ETestCase() && $ctx->plan->updatePestConfig;
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
        if (! $ctx->isQuiet() && $ctx->pestPhpHasE2ETestCase()) {
            $ctx->info('Pest config already includes E2ETestCase.');
        }
    }

    /**
     * Rewrite `tests/Pest.php` with Browser E2E extension block; persists the file and refreshes the in-memory Pest cache on success.
     */
    private function applyPestConfigUpdate(InstallContext $ctx): int
    {
        $pest = $ctx->getPestPhp();

        if (! is_string($pest)) {
            return Command::FAILURE;
        }

        $dbTrait = 'DatabaseMigrations';
        $dbTraitNamespace = 'Illuminate\Foundation\Testing\\'.$dbTrait;

        if (! str_contains($pest, 'use '.$dbTraitNamespace.';')) {
            if (preg_match('/^<\?php\s+declare\(strict_types=1\);\s*/', $pest) === 1) {
                $pest = preg_replace(
                    '/^<\?php\s+declare\(strict_types=1\);\s*/',
                    "<?php\n\ndeclare(strict_types=1);\n\nuse {$dbTraitNamespace};\n\n",
                    $pest,
                    1
                ) ?? $pest;
            } else {
                $pest = preg_replace(
                    '/^<\?php\s*/',
                    "<?php\n\nuse {$dbTraitNamespace};\n\n",
                    $pest,
                    1
                ) ?? $pest;
            }
        }

        $pest = preg_replace('/\?>\s*$/', '', $pest) ?? $pest;

        $pest .= "\n\npest()->extend(Tests\\E2ETestCase::class)\n"
            .'    ->use('.$dbTrait."::class)\n"
            ."    ->in('Browser');\n";

        if (file_put_contents($ctx->pestPhpPath(), $pest) === false) {
            return Command::FAILURE;
        }

        $ctx->pestPhp = $pest;

        return Command::SUCCESS;
    }
}
