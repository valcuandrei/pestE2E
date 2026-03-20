<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\PhpunitXmlFile;
use ValcuAndrei\PestE2E\Install\StepResult;
use ValcuAndrei\PestE2E\PHPUnit\PestE2EPhpunitExtension;

/**
 * Ensures {@see PestE2EPhpunitExtension} is registered in `phpunit.xml` (idempotent).
 */
final class RegisterPhpunitExtensionStep extends InstallStep
{
    /**
     * Always runs so registration stays in sync whenever `phpunit.xml` is present.
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($this->phpunitExtensionIsRegistered()) {
            if (! $ctx->isQuiet()) {
                $ctx->info('Pest E2E PHPUnit extension already registered.');
            }

            return StepResult::ok();
        }

        if ($this->registerPhpunitExtension() === Command::SUCCESS && ! $ctx->isQuiet()) {
            $ctx->info('Pest E2E PHPUnit extension registered.');
        }

        return StepResult::ok();
    }

    /**
     * Whether a bootstrap entry for {@see PestE2EPhpunitExtension} already exists.
     */
    private function phpunitExtensionIsRegistered(): bool
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return false;
        }

        $dom = PhpunitXmlFile::load($path);
        if (! $dom instanceof \DOMDocument) {
            return false;
        }

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//extensions/bootstrap[@class='ValcuAndrei\\PestE2E\\PHPUnit\\PestE2EPhpunitExtension']");

        return $nodes !== false && $nodes->length > 0;
    }

    /**
     * Append `<extensions><bootstrap class="...PestE2EPhpunitExtension"/>` or create `<extensions>` if missing.
     */
    private function registerPhpunitExtension(): int
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return Command::SUCCESS;
        }

        if ($this->phpunitExtensionIsRegistered()) {
            return Command::SUCCESS;
        }

        $dom = PhpunitXmlFile::load($path);
        if (! $dom instanceof \DOMDocument) {
            return Command::FAILURE;
        }

        $root = $dom->documentElement;
        if (! $root instanceof \DOMElement) {
            return Command::FAILURE;
        }

        $extensions = $dom->getElementsByTagName('extensions')->item(0);

        if ($extensions !== null) {
            $bootstrap = $dom->createElement('bootstrap');
            $bootstrap->setAttribute('class', PestE2EPhpunitExtension::class);
            $extensions->appendChild($bootstrap);
        } else {
            $extensions = $dom->createElement('extensions');
            $bootstrap = $dom->createElement('bootstrap');
            $bootstrap->setAttribute('class', PestE2EPhpunitExtension::class);
            $extensions->appendChild($bootstrap);
            $root->appendChild($extensions);
        }

        return PhpunitXmlFile::save($dom, $path) ? Command::SUCCESS : Command::FAILURE;
    }
}
