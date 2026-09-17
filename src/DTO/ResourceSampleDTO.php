<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

use ValcuAndrei\PestE2E\Contracts\ResourceSamplerContract;

/**
 * A single snapshot of host resource pressure, produced by
 * {@see ResourceSamplerContract}.
 *
 * Any metric that cannot be measured on the current platform (e.g. Windows
 * PHP without /proc, or macOS where CPU sampling requires a different code
 * path) is left `null`. The admission controller treats `null` as
 * "unavailable / fail-open" — it will not gate on a metric it can't read.
 *
 * @internal
 */
final readonly class ResourceSampleDTO
{
    /**
     * @param  float|null  $cpuUsedPct  Whole-system CPU busy percentage in [0, 100]. Null when unmeasurable.
     * @param  float|null  $memoryUsedPct  Percentage of memory in use; on Linux this is derived from
     *                                     `(MemTotal - MemAvailable) / MemTotal` (MemAvailable semantics per Andy's brief).
     *                                     Null when unmeasurable.
     * @param  array<string, int|float|string|bool|null>  $diagnostics
     *                                                                  Free-form metadata for logging: raw counters, sample intervals, PSI values, notes on why a metric
     *                                                                  was unavailable. Not part of any admission decision, only diagnostics.
     */
    public function __construct(
        public ?float $cpuUsedPct,
        public ?float $memoryUsedPct,
        public array $diagnostics = [],
    ) {}
}
