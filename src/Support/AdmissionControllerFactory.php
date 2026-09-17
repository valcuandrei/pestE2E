<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\Contracts\ResourceSamplerContract;

/**
 * Builds a fully-configured {@see AdmissionController} from `config('pest-e2e.admission')`
 * plus a resource sampler picked for the current platform.
 *
 * Kept as a static entry point so E2ERunner does not carry a growing
 * constructor argument list. Callers that want to inject a specific sampler
 * (tests) go through the underlying controller constructor directly.
 *
 * @internal
 */
final class AdmissionControllerFactory
{
    public static function make(?ResourceSamplerContract $sampler = null): AdmissionController
    {
        $cfg = self::readConfig();

        $sampler ??= self::defaultSampler((int) $cfg['cpu_sample_interval_ms']);

        return new AdmissionController(
            sampler: $sampler,
            cpuHighPct: (float) $cfg['cpu_high_pct'],
            cpuLowPct: (float) $cfg['cpu_low_pct'],
            memoryHighPct: (float) $cfg['memory_high_pct'],
            memoryLowPct: (float) $cfg['memory_low_pct'],
            cooldownMs: (int) $cfg['cooldown_ms'],
            backoffMs: (int) $cfg['backoff_ms'],
            jitterMs: (int) $cfg['jitter_ms'],
            maxWaitSeconds: (int) $cfg['max_wait_seconds'],
            enabled: (bool) $cfg['enabled'],
        );
    }

    /**
     * @return array{
     *   enabled: bool,
     *   cpu_high_pct: float,
     *   cpu_low_pct: float,
     *   memory_high_pct: float,
     *   memory_low_pct: float,
     *   cooldown_ms: int,
     *   backoff_ms: int,
     *   jitter_ms: int,
     *   max_wait_seconds: int,
     *   cpu_sample_interval_ms: int,
     * }
     */
    private static function readConfig(): array
    {
        $cfg = function_exists('config') ? config('pest-e2e.admission', []) : [];
        $cfg = is_array($cfg) ? $cfg : [];

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? true),
            'cpu_high_pct' => self::asFloat($cfg['cpu_high_pct'] ?? 80.0),
            'cpu_low_pct' => self::asFloat($cfg['cpu_low_pct'] ?? 75.0),
            'memory_high_pct' => self::asFloat($cfg['memory_high_pct'] ?? 85.0),
            'memory_low_pct' => self::asFloat($cfg['memory_low_pct'] ?? 80.0),
            'cooldown_ms' => self::asInt($cfg['cooldown_ms'] ?? 500),
            'backoff_ms' => self::asInt($cfg['backoff_ms'] ?? 500),
            'jitter_ms' => self::asInt($cfg['jitter_ms'] ?? 200),
            'max_wait_seconds' => self::asInt($cfg['max_wait_seconds'] ?? 300),
            'cpu_sample_interval_ms' => self::asInt($cfg['cpu_sample_interval_ms'] ?? 100),
        ];
    }

    private static function defaultSampler(int $intervalMs): ResourceSamplerContract
    {
        // Prefer the /proc-backed sampler on Linux (including WSL and Sail
        // containers). On Windows/macOS the /proc paths are missing —
        // PortableResourceSampler degrades gracefully to sys_getloadavg
        // for CPU and null for memory.
        if (is_readable('/proc/stat') && is_readable('/proc/meminfo')) {
            return new LinuxProcResourceSampler(sampleIntervalMs: $intervalMs);
        }

        return new PortableResourceSampler;
    }

    /**
     * @param  mixed  $value
     */
    private static function asFloat($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * @param  mixed  $value
     */
    private static function asInt($value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return 0;
    }
}
