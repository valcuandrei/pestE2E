<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Plugin as PestE2EPlugin;
use ValcuAndrei\PestE2E\PublicApi\E2E;
use ValcuAndrei\PestE2E\PublicApi\E2ETargetHandle;

if (! function_exists('pestE2ERegisterPlugin')) {
    /**
     * Ensure the Pest plugin is registered for this project.
     */
    function pestE2ERegisterPlugin(): void
    {
        $binDir = $GLOBALS['_composer_bin_dir'] ?? getcwd().'/vendor/bin';
        $pluginFile = sprintf('%s/../pest-plugins.json', $binDir);

        $plugins = [];

        if (is_file($pluginFile)) {
            $content = file_get_contents($pluginFile);
            if ($content !== false) {
                $decoded = json_decode($content, true, 512);
                if (is_array($decoded)) {
                    $plugins = array_values(array_filter($decoded, 'is_string'));
                }
            }
        }

        if (! in_array(PestE2EPlugin::class, $plugins, true)) {
            $plugins[] = PestE2EPlugin::class;
            @file_put_contents($pluginFile, json_encode($plugins, JSON_PRETTY_PRINT));
        }
    }
}

pestE2ERegisterPlugin();

if (! function_exists('e2e')) {
    /**
     * @return E2E|E2ETargetHandle
     */
    function e2e(?string $target = null)
    {
        $api = app(E2E::class);

        return $target === null
            ? $api
            : $api->targetHandle($target);
    }
}
