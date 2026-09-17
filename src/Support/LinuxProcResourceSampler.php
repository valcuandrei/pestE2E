<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\Contracts\ResourceSamplerContract;
use ValcuAndrei\PestE2E\DTO\ResourceSampleDTO;

/**
 * Linux `/proc`-backed resource sampler.
 *
 * CPU: two /proc/stat snapshots taken `sampleIntervalMs` apart, whole-system
 * busy percentage computed from the delta of the aggregate `cpu` line
 * (busy = user + nice + system + irq + softirq + steal; idle = idle + iowait).
 * Under WSL, containers, and stock Linux hosts this is the same accounting
 * `top`, `mpstat` and `htop` use, so admission thresholds set by feel from
 * those tools transfer to this sampler.
 *
 * Memory: /proc/meminfo `MemAvailable` / `MemTotal` per the kernel's own
 * definition — this is what the kernel says is available to userspace
 * without swapping, and is the metric Andy explicitly asked for.
 *
 * Both metrics are returned as `null` when the corresponding `/proc` file
 * is missing or unparseable. Callers must fail-open on `null`.
 *
 * @internal
 */
final class LinuxProcResourceSampler implements ResourceSamplerContract
{
    public function __construct(
        private readonly int $sampleIntervalMs = 100,
        private readonly string $procStatPath = '/proc/stat',
        private readonly string $procMeminfoPath = '/proc/meminfo',
    ) {}

    public function sample(): ResourceSampleDTO
    {
        $cpu = $this->sampleCpu();
        $memory = $this->sampleMemory();

        $diagnostics = [
            'sampler' => self::class,
            'cpu_sample_interval_ms' => $this->sampleIntervalMs,
        ];

        if ($cpu === null) {
            $diagnostics['cpu_unavailable_reason'] = 'proc-stat-unreadable';
        }

        if ($memory === null) {
            $diagnostics['memory_unavailable_reason'] = 'proc-meminfo-unreadable-or-no-memavailable';
        } else {
            $diagnostics['mem_available_kb'] = $memory['availableKb'];
            $diagnostics['mem_total_kb'] = $memory['totalKb'];
        }

        return new ResourceSampleDTO(
            cpuUsedPct: $cpu,
            memoryUsedPct: $memory === null ? null : $memory['usedPct'],
            diagnostics: $diagnostics,
        );
    }

    /**
     * @return float|null percentage in [0, 100], or null on read failure
     */
    private function sampleCpu(): ?float
    {
        $first = $this->readCpuAggregate();

        if ($first === null) {
            return null;
        }

        usleep(max(1, $this->sampleIntervalMs) * 1000);

        $second = $this->readCpuAggregate();

        if ($second === null) {
            return null;
        }

        $busyDelta = $second['busy'] - $first['busy'];
        $totalDelta = $second['total'] - $first['total'];

        if ($totalDelta <= 0) {
            return 0.0;
        }

        $pct = 100.0 * ($busyDelta / $totalDelta);

        return max(0.0, min(100.0, $pct));
    }

    /**
     * @return array{busy:int, total:int}|null
     */
    private function readCpuAggregate(): ?array
    {
        $contents = @file_get_contents($this->procStatPath);

        if ($contents === false || $contents === '') {
            return null;
        }

        // Aggregate is the first line beginning with `cpu ` (with the trailing space).
        $newlinePos = strpos($contents, "\n");
        $firstLine = $newlinePos === false ? $contents : substr($contents, 0, $newlinePos);

        if (! str_starts_with($firstLine, 'cpu ')) {
            return null;
        }

        // `cpu  <user> <nice> <system> <idle> <iowait> <irq> <softirq> <steal> …`
        $parts = preg_split('/\s+/', trim(substr($firstLine, 4))) ?: [];

        if (count($parts) < 4) {
            return null;
        }

        $numeric = [];
        foreach ($parts as $p) {
            if (! ctype_digit($p)) {
                return null;
            }
            $numeric[] = (int) $p;
        }

        // At this point $numeric is non-empty and has >= 4 elements, but
        // fewer than 8 is possible on ancient kernels. Pad with zeros.
        $numeric = array_pad($numeric, 8, 0);
        $user = $numeric[0];
        $nice = $numeric[1];
        $system = $numeric[2];
        $idle = $numeric[3];
        $iowait = $numeric[4];
        $irq = $numeric[5];
        $softirq = $numeric[6];
        $steal = $numeric[7];

        // Treat iowait as idle (matches `top`'s CPU line accounting); everything
        // else counts as busy.
        $busy = $user + $nice + $system + $irq + $softirq + $steal;
        $total = $busy + $idle + $iowait;

        return ['busy' => $busy, 'total' => $total];
    }

    /**
     * @return array{usedPct: float, availableKb: int, totalKb: int}|null
     */
    private function sampleMemory(): ?array
    {
        $contents = @file_get_contents($this->procMeminfoPath);

        if ($contents === false || $contents === '') {
            return null;
        }

        $totalKb = null;
        $availableKb = null;

        foreach (explode("\n", $contents) as $line) {
            if ($totalKb === null && str_starts_with($line, 'MemTotal:')) {
                $totalKb = $this->parseKb($line);
            } elseif ($availableKb === null && str_starts_with($line, 'MemAvailable:')) {
                $availableKb = $this->parseKb($line);
            }

            if ($totalKb !== null && $availableKb !== null) {
                break;
            }
        }

        if ($totalKb === null || $availableKb === null || $totalKb <= 0) {
            // Pre-3.14 kernels don't expose MemAvailable. Don't attempt a
            // free+cached fudge — return null and fail-open on this metric.
            return null;
        }

        $usedPct = 100.0 * (($totalKb - $availableKb) / $totalKb);

        return [
            'usedPct' => max(0.0, min(100.0, $usedPct)),
            'availableKb' => $availableKb,
            'totalKb' => $totalKb,
        ];
    }

    private function parseKb(string $line): ?int
    {
        if (! preg_match('/^\S+:\s+(\d+)\s*kB/i', $line, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
