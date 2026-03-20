<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

use Symfony\Component\Yaml\Yaml;

/**
 * Read-only project checks for install planning (no I/O side effects except reading files).
 */
final class InstallProjectProbe
{
    /**
     * Whether `config/pest-e2e.php` exists (published config).
     */
    public static function configExists(): bool
    {
        return is_file(config_path('pest-e2e.php'));
    }

    /**
     * Whether `tests/E2ETestCase.php` exists.
     */
    public static function e2eTestCaseExists(): bool
    {
        return is_file(base_path('tests/E2ETestCase.php'));
    }

    /**
     * Whether the JS harness `resources/js/pest-e2e/core.mjs` exists.
     */
    public static function jsHarnessExists(): bool
    {
        return is_file(resource_path('js/pest-e2e/core.mjs'));
    }

    /**
     * Whether the JS Playwright adapter `resources/js/pest-e2e/playwright.mjs` exists.
     */
    public static function jsPlaywrightExists(): bool
    {
        return is_file(resource_path('js/pest-e2e/playwright.mjs'));
    }

    /**
     * Whether `tests/Browser` exists.
     */
    public static function browserTestsExist(): bool
    {
        return is_dir(base_path('tests/Browser'));
    }

    /**
     * Whether `resources/js/e2e` exists (published Playwright JS tests).
     */
    public static function playwrightTestsExist(): bool
    {
        return is_dir(resource_path('js/e2e'));
    }

    /**
     * Whether `.env.testing` exists at project root.
     */
    public static function envTestingExists(): bool
    {
        return is_file(base_path('.env.testing'));
    }

    /**
     * Whether `database/testing.sqlite` exists.
     */
    public static function testingDatabaseExists(): bool
    {
        return is_file(base_path('database/testing.sqlite'));
    }

    /**
     * Whether `phpunit.xml` still exposes DB/cache env elements (if none, `.env.testing` is considered in control).
     */
    public static function phpunitIsConfiguredForEnvTesting(): bool
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
        $varsToCheck = ['DB_CONNECTION', 'DB_DATABASE', 'CACHE_STORE', 'SESSION_DRIVER'];

        foreach ($varsToCheck as $var) {
            $nodes = $xpath->query("//php/env[@name='{$var}']");
            if ($nodes !== false && $nodes->length > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * First existing Compose file path in Laravel/Sail default filename order, or null.
     */
    public static function resolveComposeFilePath(): ?string
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'] as $name) {
            $path = base_path($name);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Whether `composer.json` lists `laravel/sail` in require or require-dev.
     */
    public static function composerDeclaresLaravelSail(): bool
    {
        $path = base_path('composer.json');
        if (! is_file($path)) {
            return false;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return false;
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! is_array($data)) {
            return false;
        }

        foreach (['require', 'require-dev'] as $section) {
            if (! isset($data[$section])) {
                continue;
            }
            if (! is_array($data[$section])) {
                continue;
            }
            if (array_key_exists('laravel/sail', $data[$section])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the project looks like Sail: compose file + sail package + `laravel.test` service.
     */
    public static function sailProjectDetected(): bool
    {
        $composePath = self::resolveComposeFilePath();
        if ($composePath === null) {
            return false;
        }

        if (! is_dir(base_path('vendor/laravel/sail')) && ! self::composerDeclaresLaravelSail()) {
            return false;
        }

        try {
            $data = Yaml::parseFile($composePath);
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($data)) {
            return false;
        }

        $services = $data['services'] ?? null;
        if (! is_array($services)) {
            return false;
        }

        $laravelTest = $services['laravel.test'] ?? null;

        return is_array($laravelTest);
    }

    /**
     * Whether the resolved compose file already has WSLg env + volume mounts on `laravel.test`.
     */
    public static function composeFileHasSailWslgHeadedConfig(): bool
    {
        $composePath = self::resolveComposeFilePath();
        if ($composePath === null || ! is_readable($composePath)) {
            return false;
        }

        try {
            $data = Yaml::parseFile($composePath);
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($data)) {
            return false;
        }

        $services = $data['services'] ?? null;
        if (! is_array($services)) {
            return false;
        }

        $service = $services['laravel.test'] ?? null;
        if (! is_array($service)) {
            return false;
        }

        return self::serviceHasWslgEnvironment($service)
            && self::serviceHasWslgVolumes($service);
    }

    /**
     * Whether `laravel.test.environment` lists the WSLg-related keys (map or list form).
     *
     * @param  array<mixed>  $service  Docker Compose service definition.
     */
    public static function serviceHasWslgEnvironment(array $service): bool
    {
        $keys = ['DISPLAY', 'WAYLAND_DISPLAY', 'XDG_RUNTIME_DIR', 'PULSE_SERVER'];
        $env = $service['environment'] ?? null;
        if (! is_array($env)) {
            return false;
        }

        if ($env !== [] && array_is_list($env)) {
            foreach ($keys as $key) {
                $found = false;
                foreach ($env as $entry) {
                    if (! is_string($entry)) {
                        continue;
                    }
                    $trimmed = trim($entry);
                    if (str_starts_with($trimmed, $key.'=') || str_starts_with($trimmed, $key.':')) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return false;
                }
            }

            return true;
        }

        foreach ($keys as $key) {
            if (! array_key_exists($key, $env)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `laravel.test.volumes` includes WSLg bind mounts for headed Playwright in Docker.
     *
     * @param  array<mixed>  $service  Docker Compose service definition.
     */
    public static function serviceHasWslgVolumes(array $service): bool
    {
        $required = ['/mnt/wslg:/mnt/wslg', '/tmp/.X11-unix:/tmp/.X11-unix'];
        $volumes = $service['volumes'] ?? null;
        if (! is_array($volumes) || ! array_is_list($volumes)) {
            return false;
        }

        foreach ($required as $mount) {
            if (! in_array($mount, $volumes, true)) {
                return false;
            }
        }

        return true;
    }
}
