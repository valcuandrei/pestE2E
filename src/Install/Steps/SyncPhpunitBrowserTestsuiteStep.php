<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install\Steps;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\Install\InstallContext;
use ValcuAndrei\PestE2E\Install\InstallStep;
use ValcuAndrei\PestE2E\Install\PhpunitXmlFile;
use ValcuAndrei\PestE2E\Install\StepResult;

/**
 * Ensures `phpunit.xml` contains a `Browser` testsuite pointing at `tests/Browser`.
 */
final class SyncPhpunitBrowserTestsuiteStep extends InstallStep
{
    /**
     * {@inheritdoc}
     */
    public function shouldRun(InstallContext $ctx): bool
    {
        return is_file(base_path('phpunit.xml'));
    }

    /**
     * {@inheritdoc}
     */
    public function run(InstallContext $ctx): StepResult
    {
        if ($this->syncPhpunitBrowserTestsuite() === Command::FAILURE) {
            if (! $ctx->isQuiet()) {
                $ctx->error('Failed to add Browser testsuite to phpunit.xml');
            }

            return StepResult::fail();
        }

        return StepResult::ok();
    }

    /**
     * Load `phpunit.xml`, add the Browser suite if missing, and save.
     */
    private function syncPhpunitBrowserTestsuite(): int
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return Command::SUCCESS;
        }

        $dom = PhpunitXmlFile::load($path);
        if (! $dom instanceof \DOMDocument) {
            return Command::FAILURE;
        }

        if ($this->phpunitBrowserTestsuiteConfigured($dom)) {
            return Command::SUCCESS;
        }

        $this->ensurePhpunitBrowserTestsuiteOnDom($dom);

        return PhpunitXmlFile::save($dom, $path) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Whether any `<testsuite><directory>` already references `tests/Browser`.
     */
    private function phpunitBrowserTestsuiteConfigured(\DOMDocument $dom): bool
    {
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//testsuite/directory');
        if ($nodes === false) {
            return false;
        }

        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement && trim($node->textContent) === 'tests/Browser') {
                return true;
            }
        }

        return false;
    }

    /**
     * Insert a `<testsuites>` block (if needed) and append the Browser testsuite with `tests/Browser`.
     */
    private function ensurePhpunitBrowserTestsuiteOnDom(\DOMDocument $dom): void
    {
        if ($this->phpunitBrowserTestsuiteConfigured($dom)) {
            return;
        }

        $root = $dom->documentElement;
        if (! $root instanceof \DOMElement) {
            return;
        }

        $xpath = new \DOMXPath($dom);
        $testsuitesNodes = $xpath->query('./testsuites', $root);
        $testsuites = ($testsuitesNodes !== false && $testsuitesNodes->length > 0)
            ? $testsuitesNodes->item(0)
            : null;

        if (! $testsuites instanceof \DOMElement) {
            $testsuites = $dom->createElement('testsuites');
            $insertBefore = null;
            foreach ($root->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $insertBefore = $child;

                    break;
                }
            }
            if ($insertBefore instanceof \DOMNode) {
                $root->insertBefore($testsuites, $insertBefore);
            } else {
                $root->appendChild($testsuites);
            }
        }

        $suite = $dom->createElement('testsuite');
        $suite->setAttribute('name', 'Browser');
        $suite->appendChild($dom->createElement('directory', 'tests/Browser'));
        $testsuites->appendChild($suite);
    }
}
