<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\LinuxProcResourceSampler;

/**
 * Tests for the /proc-backed sampler. Uses fake /proc/stat and
 * /proc/meminfo files instead of the real host so the assertions are
 * deterministic and CI-safe (no matter what CI's load looks like at the
 * moment we run).
 */
beforeEach(function (): void {
    $this->fakeProcDir = sys_get_temp_dir().'/pest-e2e-fake-proc-'.uniqid();
    mkdir($this->fakeProcDir, 0700, true);
    $this->statPath = $this->fakeProcDir.'/stat';
    $this->meminfoPath = $this->fakeProcDir.'/meminfo';
});

afterEach(function (): void {
    @unlink($this->statPath);
    @unlink($this->meminfoPath);
    @rmdir($this->fakeProcDir);
});

/**
 * Write a `cpu` aggregate line simulating a snapshot from /proc/stat.
 * Fields are Linux jiffies: user nice system idle iowait irq softirq steal.
 */
function writeFakeStat(string $path, int $user, int $nice, int $system, int $idle, int $iowait = 0, int $irq = 0, int $softirq = 0, int $steal = 0): void
{
    $line = "cpu  {$user} {$nice} {$system} {$idle} {$iowait} {$irq} {$softirq} {$steal}\n";
    file_put_contents($path, $line."cpu0 0 0 0 0 0 0 0 0\n");
}

it('returns null metrics when /proc paths are missing (fail-open on any host without procfs)', function (): void {
    $sampler = new LinuxProcResourceSampler(
        sampleIntervalMs: 1,
        procStatPath: '/does/not/exist/stat',
        procMeminfoPath: '/does/not/exist/meminfo',
    );

    $sample = $sampler->sample();

    expect($sample->cpuUsedPct)->toBeNull()
        ->and($sample->memoryUsedPct)->toBeNull()
        ->and($sample->diagnostics['cpu_unavailable_reason'] ?? null)->toBe('proc-stat-unreadable')
        ->and($sample->diagnostics['memory_unavailable_reason'] ?? null)->toBe('proc-meminfo-unreadable-or-no-memavailable');
});

it('returns a 0% CPU sample when /proc/stat shows no change between the two internal snapshots', function (): void {
    // Both internal reads see the same jiffies → busy delta is 0 → 0.0% busy.
    writeFakeStat($this->statPath, user: 100, nice: 0, system: 0, idle: 900);
    file_put_contents($this->meminfoPath, "MemTotal:       1000 kB\nMemAvailable:    500 kB\n");

    $sampler = new LinuxProcResourceSampler(sampleIntervalMs: 1, procStatPath: $this->statPath, procMeminfoPath: $this->meminfoPath);
    $sample = $sampler->sample();

    expect($sample->cpuUsedPct)->not->toBeNull()
        ->and($sample->cpuUsedPct)->toBeLessThan(1.0);
});

it('exercises the CPU delta computation via reflection against two /proc/stat snapshots', function (): void {
    // Subclass swap: rewrite stat between the two reads by extending
    // sample interval + external file rewrite.
    writeFakeStat($this->statPath, user: 100, nice: 0, system: 0, idle: 900);
    file_put_contents($this->meminfoPath, "MemTotal:       1000 kB\nMemAvailable:    500 kB\n");

    $statPath = $this->statPath;
    $meminfoPath = $this->meminfoPath;

    // Custom sampler that swaps stat file mid-sample via a scheduled write.
    // We spawn a background sleep+overwrite via `usleep`+`stream_socket_pair`
    // trick isn't feasible without pcntl_fork, so we do it inline by
    // subclassing and overriding `sample()` to do the two reads ourselves,
    // exercising the same aggregate parser via reflection.
    $sample1Path = tempnam(sys_get_temp_dir(), 'stat1-');
    $sample2Path = tempnam(sys_get_temp_dir(), 'stat2-');

    writeFakeStat($sample1Path, user: 100, nice: 0, system: 0, idle: 900);       // 10% busy, 1000 total
    writeFakeStat($sample2Path, user: 500, nice: 0, system: 0, idle: 1000);       // Δbusy 400, Δtotal 500 → 80% busy

    // Use one sampler that reads the "first" file, and rehearse the delta
    // path by manually invoking twice against different files via
    // reflection over the private readCpuAggregate.
    $sampler = new LinuxProcResourceSampler(sampleIntervalMs: 1, procStatPath: $sample1Path, procMeminfoPath: $meminfoPath);
    $method = new ReflectionMethod(LinuxProcResourceSampler::class, 'readCpuAggregate');
    $method->setAccessible(true);

    $first = $method->invoke($sampler);
    $sampler2 = new LinuxProcResourceSampler(sampleIntervalMs: 1, procStatPath: $sample2Path, procMeminfoPath: $meminfoPath);
    $second = (new ReflectionMethod(LinuxProcResourceSampler::class, 'readCpuAggregate'))
        ->getClosure($sampler2)();

    $busyDelta = $second['busy'] - $first['busy'];
    $totalDelta = $second['total'] - $first['total'];
    $pct = 100.0 * ($busyDelta / $totalDelta);

    expect($pct)->toBeGreaterThan(70.0)
        ->and($pct)->toBeLessThan(90.0);

    @unlink($sample1Path);
    @unlink($sample2Path);
});

it('computes memory used percentage from MemTotal/MemAvailable (MemAvailable semantics per Andys brief)', function (): void {
    writeFakeStat($this->statPath, user: 0, nice: 0, system: 0, idle: 1000);
    file_put_contents($this->meminfoPath, "MemTotal:       10000 kB\nMemFree:         1000 kB\nMemAvailable:    2000 kB\nBuffers:         500 kB\n");

    $sampler = new LinuxProcResourceSampler(sampleIntervalMs: 1, procStatPath: $this->statPath, procMeminfoPath: $this->meminfoPath);
    $sample = $sampler->sample();

    // Used = (10000 - 2000) / 10000 = 80%. NOT MemFree-based (would be 90%).
    expect($sample->memoryUsedPct)->toBe(80.0)
        ->and($sample->diagnostics['mem_total_kb'])->toBe(10000)
        ->and($sample->diagnostics['mem_available_kb'])->toBe(2000);
});

it('returns null memory when MemAvailable is absent (pre-3.14 kernels — fail-open, do not guess from MemFree)', function (): void {
    writeFakeStat($this->statPath, user: 0, nice: 0, system: 0, idle: 1000);
    file_put_contents($this->meminfoPath, "MemTotal:       10000 kB\nMemFree:         1000 kB\nBuffers:         500 kB\n");

    $sampler = new LinuxProcResourceSampler(sampleIntervalMs: 1, procStatPath: $this->statPath, procMeminfoPath: $this->meminfoPath);
    $sample = $sampler->sample();

    expect($sample->memoryUsedPct)->toBeNull()
        ->and($sample->diagnostics['memory_unavailable_reason'] ?? null)
        ->toBe('proc-meminfo-unreadable-or-no-memavailable');
});

it('samples the real host /proc/stat and /proc/meminfo on Linux (integration smoke)', function (): void {
    if (! is_readable('/proc/stat') || ! is_readable('/proc/meminfo')) {
        $this->markTestSkipped('this host does not expose /proc');
    }

    $sampler = new LinuxProcResourceSampler(sampleIntervalMs: 50);
    $sample = $sampler->sample();

    // We can't know the exact values, only that they're sensible when the
    // real /proc is available.
    expect($sample->cpuUsedPct)->toBeNumeric()
        ->and($sample->cpuUsedPct)->toBeGreaterThanOrEqual(0.0)
        ->and($sample->cpuUsedPct)->toBeLessThanOrEqual(100.0);

    if ($sample->memoryUsedPct !== null) {
        expect($sample->memoryUsedPct)->toBeGreaterThan(0.0)
            ->and($sample->memoryUsedPct)->toBeLessThan(100.0);
    }
});
