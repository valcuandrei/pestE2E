<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

/**
 * Detects and repairs Feature-suite RefreshDatabase configuration in `tests/Pest.php`.
 */
final class PestFeatureSuiteInspector
{
    /**
     * Whether Feature tests actively use `RefreshDatabase` (not commented out).
     */
    public static function hasActiveRefreshDatabase(string $pest): bool
    {
        if (self::usesBlockHasActiveRefreshDatabase(self::extractFeatureUsesBlock($pest))) {
            return true;
        }

        return self::pestExtendChainHasActiveRefreshDatabase(self::extractFeaturePestExtendChain($pest));
    }

    /**
     * Whether the Feature suite references `RefreshDatabase` only in comments.
     */
    public static function hasCommentedRefreshDatabase(string $pest): bool
    {
        if (self::hasActiveRefreshDatabase($pest)) {
            return false;
        }
        if (self::blockMentionsRefreshDatabase(self::extractFeatureUsesBlock($pest))) {
            return true;
        }

        return self::blockMentionsRefreshDatabase(self::extractFeaturePestExtendChain($pest));
    }

    /**
     * Uncomment `RefreshDatabase` inside the Feature suite when present but commented.
     */
    public static function uncommentRefreshDatabase(string $pest): string
    {
        if (preg_match('/uses\s*\(((?:.|\n)*?)\)\s*->in\([\'"]Feature[\'"]\)/s', $pest, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $block = $matches[0][0];
            $updated = self::uncommentRefreshDatabaseLines($block);

            if ($updated !== $block) {
                $pest = substr_replace($pest, $updated, $matches[0][1], strlen($block));
            }
        }

        if (preg_match('/pest\(\)->extend\([^)]+\)((?:.|\n)*?)->in\([\'"]Feature[\'"]\)/s', $pest, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $block = $matches[0][0];
            $updated = self::uncommentRefreshDatabaseLines($block);

            if ($updated !== $block) {
                $pest = substr_replace($pest, $updated, $matches[0][1], strlen($block));
            }
        }

        return $pest;
    }

    private static function extractFeatureUsesBlock(string $pest): ?string
    {
        if (preg_match('/uses\s*\(((?:.|\n)*?)\)\s*->in\([\'"]Feature[\'"]\)/s', $pest, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private static function extractFeaturePestExtendChain(string $pest): ?string
    {
        if (preg_match('/pest\(\)->extend\([^)]+\)((?:.|\n)*?)->in\([\'"]Feature[\'"]\)/s', $pest, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private static function usesBlockHasActiveRefreshDatabase(?string $block): bool
    {
        if ($block === null) {
            return false;
        }

        foreach (self::lines($block) as $line) {
            if (! str_contains($line, 'RefreshDatabase')) {
                continue;
            }

            if (self::lineIsComment($line)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private static function pestExtendChainHasActiveRefreshDatabase(?string $block): bool
    {
        if ($block === null) {
            return false;
        }

        foreach (self::lines($block) as $line) {
            if (! str_contains($line, 'RefreshDatabase')) {
                continue;
            }

            if (self::lineIsComment($line)) {
                continue;
            }

            if (preg_match('/->use\(\s*.*RefreshDatabase::class\s*\)/', $line) === 1) {
                return true;
            }

            if (preg_match('/RefreshDatabase::class/', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function blockMentionsRefreshDatabase(?string $block): bool
    {
        return $block !== null && str_contains($block, 'RefreshDatabase');
    }

    private static function uncommentRefreshDatabaseLines(string $block): string
    {
        $lines = self::lines($block);
        $changed = false;

        foreach ($lines as $index => $line) {
            if (! str_contains($line, 'RefreshDatabase')) {
                continue;
            }

            if (! self::lineIsComment($line)) {
                continue;
            }

            $lines[$index] = preg_replace(
                '/^(\s*)\/\/\s*/',
                '$1',
                $line,
                1
            ) ?? $line;
            $changed = true;
        }

        if (! $changed) {
            return $block;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function lines(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);

        return is_array($lines) ? $lines : [];
    }

    private static function lineIsComment(string $line): bool
    {
        return preg_match('/^\s*\/\//', $line) === 1;
    }
}
