<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Install;

/**
 * Predicts which `DB_CONNECTION` `.env.testing` will use after install steps run.
 */
final class EnvTestingConnectionResolver
{
    /**
     * Resolve the effective testing database driver for install decisions.
     *
     * @param  bool  $willSetupOrUpdateEnvTesting  Whether {@see CreateEnvTestingStep} will run.
     * @param  bool  $force  Installer `--force` flag.
     * @param  bool  $autoConsent  `--yes` or `--unattended`.
     */
    public static function resolve(
        bool $willSetupOrUpdateEnvTesting,
        bool $force,
        bool $autoConsent,
    ): string {
        if (InstallProjectProbe::envTestingExists()) {
            $values = InstallProjectProbe::parseEnvFile(base_path('.env.testing'));
            $connection = self::normalize($values['DB_CONNECTION'] ?? '');

            if ($connection === 'sqlite' && $willSetupOrUpdateEnvTesting && ($force || $autoConsent)) {
                return 'mysql';
            }

            if ($connection !== '') {
                return $connection;
            }

            return $willSetupOrUpdateEnvTesting ? 'mysql' : self::fallbackConnection();
        }

        if ($willSetupOrUpdateEnvTesting) {
            return 'mysql';
        }

        return self::fallbackConnection();
    }

    /**
     * Read `DB_CONNECTION` from `.env.testing` when present.
     */
    public static function fromEnvTestingFile(): string
    {
        if (! InstallProjectProbe::envTestingExists()) {
            return '';
        }

        $values = InstallProjectProbe::parseEnvFile(base_path('.env.testing'));

        return self::normalize($values['DB_CONNECTION'] ?? '');
    }

    private static function fallbackConnection(): string
    {
        $fromPhpunit = self::phpunitEnvValue('DB_CONNECTION');
        if ($fromPhpunit !== '') {
            return self::normalize($fromPhpunit);
        }

        if (is_file(base_path('.env'))) {
            $values = InstallProjectProbe::parseEnvFile(base_path('.env'));
            $fromEnv = self::normalize($values['DB_CONNECTION'] ?? '');
            if ($fromEnv !== '') {
                return $fromEnv;
            }
        }

        return 'sqlite';
    }

    private static function phpunitEnvValue(string $name): string
    {
        $path = base_path('phpunit.xml');
        if (! is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return '';
        }

        if (preg_match('/<env\s+name="'.preg_quote($name, '/').'"\s+value="([^"]*)"/', $contents, $matches) === 1) {
            return self::normalize($matches[1]);
        }

        return '';
    }

    private static function normalize(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return strtolower(substr($value, 1, -1));
        }

        return strtolower($value);
    }
}
