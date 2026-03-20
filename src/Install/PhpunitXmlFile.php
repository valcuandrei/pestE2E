<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

/**
 * Minimal DOM load/save helpers for `phpunit.xml` used by install steps.
 */
final class PhpunitXmlFile
{
    /**
     * Parse `phpunit.xml` into a DOM document, or null if invalid/unreadable.
     */
    public static function load(string $path): ?\DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (@$dom->load($path) === false) {
            return null;
        }

        return $dom;
    }

    /**
     * Persist `phpunit.xml` from a DOM tree.
     */
    public static function save(\DOMDocument $dom, string $path): bool
    {
        return $dom->save($path) !== false;
    }
}
