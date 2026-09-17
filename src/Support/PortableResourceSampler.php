<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\Contracts\ResourceSamplerContract;
use ValcuAndrei\PestE2E\DTO\ResourceSampleDTO;

/**
 * Cross-platform best-effort resource sampler. Used when `/proc/stat` and
 * `/proc/meminfo` are not available (Windows, macOS, minimal Docker images
 * without procfs).
 *
 * CPU: `sys_getloadavg()[0] / nproc` * 100, clamped to [0, 100]. Load average
 * is a moving 1-minute figure so this metric is coarse — do not expect it
 * to react as fast as a fresh /proc/stat delta. On systems where
 * `sys_getloadavg` returns false (e.g. some Windows builds), null.
 *
 * Memory: not measurable portably from userland PHP — always null.
 *
 * Callers must fail-open on null metrics. On Windows/macOS this sampler
 * effectively degrades the controller into "no gating" for memory, and
 * "coarse gating from load average" for CPU. That matches Andy's brief:
 * "if a metric genuinely cannot be measured on a platform, fail open for
 * that metric rather than deadlocking pest-e2e".
 *
 * @internal
 */
final class PortableResourceSampler implements ResourceSamplerContract
{
    public function sample(): ResourceSampleDTO
    {
        $cpuUsedPct = null;
        $diagnostics = ['sampler' => self::class];

        if (function_exists('sys_getloadavg')) {
            /** @var array{0: float, 1: float, 2: float}|false $load */
            $load = @sys_getloadavg();

            if (is_array($load)) {
                $cores = $this->cpuCount();
                $cpuUsedPct = $cores > 0
                    ? max(0.0, min(100.0, 100.0 * ($load[0] / $cores)))
                    : null;
                $diagnostics['load1'] = $load[0];
                $diagnostics['cpu_count'] = $cores;
            } else {
                $diagnostics['cpu_unavailable_reason'] = 'sys_getloadavg-returned-false';
            }
        } else {
            $diagnostics['cpu_unavailable_reason'] = 'sys_getloadavg-missing';
        }

        $diagnostics['memory_unavailable_reason'] = 'portable-sampler-cannot-measure-memory';

        return new ResourceSampleDTO(
            cpuUsedPct: $cpuUsedPct,
            memoryUsedPct: null,
            diagnostics: $diagnostics,
        );
    }

    private function cpuCount(): int
    {
        // Container-aware detection is intentionally NOT attempted here — the
        // load average is already reported against the whole visible-CPU set,
        // so the normalisation must use the same denominator.
        if (function_exists('shell_exec')) {
            $out = @shell_exec('nproc 2>/dev/null');

            if (is_string($out) && ctype_digit(trim($out))) {
                $n = (int) trim($out);
                if ($n > 0) {
                    return $n;
                }
            }
        }

        $stat = @file_get_contents('/proc/cpuinfo');
        if (is_string($stat) && $stat !== '') {
            $count = substr_count($stat, "\nprocessor\t");
            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }
}
