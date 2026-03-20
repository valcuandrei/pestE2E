<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

use ValcuAndrei\PestE2E\Support\JsPackageManager;

use function Laravel\Prompts\select;

/**
 * Resolves which package manager key to use for JS/Playwright and injects it into the published E2ETestCase stub.
 */
final class InstallPackageManagerResolver
{
    /**
     * Resolved package manager key for this install run (stub + Playwright install), cached on {@see InstallContext::$memoizedE2ePackageManagerKey}.
     */
    public static function e2ePackageManagerKey(InstallContext $ctx): string
    {
        if ($ctx->memoizedE2ePackageManagerKey !== null) {
            return $ctx->memoizedE2ePackageManagerKey;
        }

        return $ctx->memoizedE2ePackageManagerKey = self::resolveFresh($ctx);
    }

    /**
     * Replace `{{PACKAGE_MANAGER}}` in `tests/E2ETestCase.php` after publish.
     */
    public static function injectIntoE2ETestCase(InstallContext $ctx): void
    {
        $path = base_path('tests/E2ETestCase.php');
        if (! is_file($path)) {
            return;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return;
        }

        $detected = self::e2ePackageManagerKey($ctx);
        $content = str_replace('{{PACKAGE_MANAGER}}', $detected, $content);

        file_put_contents($path, $content);
    }

    /**
     * Compute package manager key from `--package-manager`, lockfiles, available binaries, and optional interactive select.
     */
    private static function resolveFresh(InstallContext $ctx): string
    {
        $forced = $ctx->option('package-manager');
        if (is_string($forced) && $forced !== '') {
            return $forced;
        }

        $js = $ctx->jsPackageManager;
        $available = $js->getAvailablePackageManagers();

        if ($available === []) {
            return $js->defaultPackageManagerKeyFromLockfiles();
        }

        $ordered = self::orderPackageManagerKeysByRegistration($js, $available);

        if (count($ordered) === 1) {
            return $ordered[0];
        }

        $lockfileChoice = $js->defaultPackageManagerKeyFromLockfiles();

        if ($ctx->isInteractive()) {
            $default = in_array($lockfileChoice, $ordered, true) ? $lockfileChoice : $ordered[0];

            /** @var string */
            return select(
                'Which package manager should Pest E2E use for JS / Playwright commands?',
                $ordered,
                $default,
            );
        }

        if (in_array($lockfileChoice, $ordered, true)) {
            return $lockfileChoice;
        }

        return $ordered[0];
    }

    /**
     * Order candidate keys by {@see JsPackageManager::packageManagerKeysInRegistrationOrder()}, then append any leftovers.
     *
     * @param  array<string>  $keys
     * @return list<string>
     */
    private static function orderPackageManagerKeysByRegistration(JsPackageManager $js, array $keys): array
    {
        /** @var array<string, int> $want */
        $want = array_flip($keys);
        $ordered = [];
        foreach ($js->packageManagerKeysInRegistrationOrder() as $key) {
            if (isset($want[$key])) {
                $ordered[] = $key;
            }
        }

        foreach ($keys as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        return $ordered;
    }
}
