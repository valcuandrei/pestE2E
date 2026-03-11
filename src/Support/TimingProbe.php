<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use JsonException;
use Throwable;

/**
 * @internal
 */
final class TimingProbe
{
    /**
     * @var string
     */
    private const PREFIX = '[pest-e2e:timing]';

    /**
     * Check if the timing probe is enabled.
     */
    public static function isEnabled(?string $rawValue = null): bool
    {
        if ($rawValue !== null) {
            return self::toBool($rawValue);
        }

        $envValue = $_ENV['PEST_E2E_TIMING'] ?? getenv('PEST_E2E_TIMING');
        if (is_string($envValue)) {
            return self::toBool($envValue);
        }

        $configValue = self::readConfigValue();
        if ($configValue !== null) {
            return $configValue;
        }

        return false;
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $meta
     */
    public static function mark(string $phase, array $meta = []): void
    {
        if (! self::isEnabled()) {
            return;
        }

        $payload = array_merge(
            [
                'phase' => $phase,
                'atMs' => self::timestampMs(),
            ],
            $meta,
        );

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        fwrite(STDERR, self::PREFIX.' '.$encoded.PHP_EOL);
    }

    /**
     * Get the elapsed milliseconds since the start time.
     */
    public static function elapsedMs(float $start): int
    {
        return max(0, (int) round((microtime(true) - $start) * 1000));
    }

    /**
     * Get the current timestamp in milliseconds.
     */
    private static function timestampMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Convert a string to a boolean.
     */
    private static function toBool(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Read the config value.
     */
    private static function readConfigValue(): ?bool
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $app = app();

            if (! $app->bound('config')) {
                return null;
            }

            $config = $app->make('config');

            if (! $config instanceof ConfigRepositoryContract) {
                return null;
            }

            $value = $config->get('pest-e2e.timing.enabled', false);

            return (bool) $value;
        } catch (Throwable) {
            return null;
        }
    }
}
