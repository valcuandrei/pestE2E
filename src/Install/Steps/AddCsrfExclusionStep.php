<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Patches `bootstrap/app.php` to exclude the pest-e2e auth route from CSRF verification when possible.
 */
final class AddCsrfExclusionStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return $ctx->plan->addCsrfExclusion;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($this->applyCsrfExclusionPatch() === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info('CSRF exclusion for pest-e2e auth route added successfully.');
            }
        } elseif (! $ctx->isQuiet()) {
            $ctx->warn('Could not add CSRF exclusion. Add manually: $middleware->validateCsrfTokens(except: [\'/pest-e2e/auth/login\']);');
        }

        return StepResult::ok();
    }

    /**
     * Insert CSRF exclusion after `encryptCookies` when not already present; never fails the installer (warn only).
     */
    private function applyCsrfExclusionPatch(): int
    {
        $path = base_path('bootstrap/app.php');
        if (! is_file($path)) {
            return Command::FAILURE;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return Command::FAILURE;
        }
        $exclusion = "validateCsrfTokens(except: ['/pest-e2e/auth/login'])";
        if (str_contains($content, $exclusion) || str_contains($content, 'pest-e2e/auth/login')) {
            return Command::SUCCESS;
        }
        $newContent = preg_replace(
            '/(\$middleware->encryptCookies\([^)]+\);)/',
            '$1'."\n        \$middleware->".$exclusion.';',
            $content,
            1
        );
        if ($newContent === null || $newContent === $content) {
            return Command::FAILURE;
        }

        return file_put_contents($path, $newContent) !== false ? Command::SUCCESS : Command::FAILURE;
    }
}
