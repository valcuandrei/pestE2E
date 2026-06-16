<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Stringable;

/**
 * @internal
 */
final class ProcessEnvironment
{
    /**
     * @param  array<mixed>|null  $env
     * @return array<string, string|Stringable|false>|null
     */
    public static function normalize(?array $env): ?array
    {
        if ($env === null) {
            return null;
        }

        $normalized = [];

        foreach ($env as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if ($value === null) {
                $normalized[$key] = false;

                continue;
            }

            if (is_string($value) || $value instanceof Stringable || $value === false) {
                $normalized[$key] = $value;

                continue;
            }

            if (is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}
