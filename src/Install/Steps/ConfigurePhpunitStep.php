<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallProjectProbe;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\PhpunitXmlFile;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Comments DB/cache `<php><env>` entries in `phpunit.xml` so `.env.testing` drives E2E state.
 */
final class ConfigurePhpunitStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return $ctx->plan->configurePhpunit;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($this->configurePhpunitForEnvTesting($ctx) === Command::SUCCESS) {
            if (! $ctx->isQuiet()) {
                $ctx->info('phpunit.xml configured for .env.testing.');
            }

            return StepResult::ok();
        }

        if (! $ctx->isQuiet()) {
            $ctx->error('Failed to configure phpunit.xml');
        }

        return StepResult::fail();
    }

    /**
     * {@inheritdoc}
     */
    public function afterSkipped(InstallContext $ctx): void
    {
        if (! $ctx->isQuiet() && InstallProjectProbe::phpunitIsConfiguredForEnvTesting()) {
            $ctx->info('phpunit.xml already configured for .env.testing.');
        }
    }

    /**
     * Replace matching `<env>` nodes with XML comments and add an explanatory block comment under `<php>`.
     */
    private function configurePhpunitForEnvTesting(InstallContext $ctx): int
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return Command::FAILURE;
        }

        if (InstallProjectProbe::phpunitIsConfiguredForEnvTesting() && ! $ctx->force) {
            return Command::SUCCESS;
        }

        $dom = PhpunitXmlFile::load($path);
        if (! $dom instanceof \DOMDocument) {
            return Command::FAILURE;
        }

        $xpath = new \DOMXPath($dom);
        $varsToComment = ['DB_CONNECTION', 'DB_DATABASE', 'CACHE_STORE', 'SESSION_DRIVER'];

        foreach ($varsToComment as $var) {
            $nodes = $xpath->query("//php/env[@name='{$var}']");
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $env) {
                if (! $env instanceof \DOMElement) {
                    continue;
                }
                $xml = $dom->saveXML($env);
                if ($xml === false) {
                    continue;
                }
                $comment = $dom->createComment(' '.trim($xml).' ');
                $parent = $env->parentNode;
                if ($parent instanceof \DOMNode) {
                    $parent->replaceChild($comment, $env);
                }
            }
        }

        $phpNodes = $dom->getElementsByTagName('php');
        $php = $phpNodes->item(0);
        if ($php !== null) {
            $hasE2EComment = false;
            foreach ($php->childNodes as $child) {
                if ($child instanceof \DOMComment && str_contains($child->data, 'E2E: Omit')) {
                    $hasE2EComment = true;
                    break;
                }
            }
            if (! $hasE2EComment) {
                $e2eComment = $dom->createComment(' E2E: Omit DB/cache env so .env.testing controls them (required for auth ticket sharing) ');
                $php->insertBefore($e2eComment, $php->firstChild);
            }
        }

        return PhpunitXmlFile::save($dom, $path) ? Command::SUCCESS : Command::FAILURE;
    }
}
