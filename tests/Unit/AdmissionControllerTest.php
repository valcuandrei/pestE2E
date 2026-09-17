<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Support\AdmissionController;
use ValcuAndrei\PestE2E\Support\FakeResourceSampler;

/**
 * Admission-controller decision-logic tests.
 *
 * All tests inject:
 *   - a `FakeResourceSampler` with a scripted queue of (cpu, memory) samples,
 *     so we exercise specific pressure curves without touching the real host;
 *   - a virtual clock that advances by the amount the sleeper was asked to
 *     sleep, so max-wait / cooldown / backoff behaviour is deterministic and
 *     wall-clock free;
 *   - a fresh temp-directory lock path so the state file starts empty.
 */
beforeEach(function (): void {
    $this->lockDir = sys_get_temp_dir().'/pest-e2e-admission-tests-'.uniqid();
    mkdir($this->lockDir, 0700, true);
    $this->lockPath = $this->lockDir.'/admission.lock';

    $this->now = 1000.0;
    $this->clock = function () {
        return $this->now;
    };
    $this->slept = 0.0;
    $this->sleeper = function (float $seconds) {
        $this->slept += $seconds;
        $this->now += $seconds;
    };
});

afterEach(function (): void {
    // Remove lock + state.
    @unlink($this->lockPath);
    @unlink($this->lockPath.'.state');
    @rmdir($this->lockDir);
});

function makeController(callable $samplerBuilder, array $overrides = [], $clock = null, $sleeper = null): AdmissionController
{
    $sampler = $samplerBuilder(new FakeResourceSampler);
    $defaults = [
        'cpuHighPct' => 80.0,
        'cpuLowPct' => 75.0,
        'memoryHighPct' => 85.0,
        'memoryLowPct' => 80.0,
        'cooldownMs' => 0,
        'backoffMs' => 10,
        'jitterMs' => 0,
        'maxWaitSeconds' => 60,
        'lockPath' => null,
        'enabled' => true,
    ];
    $cfg = array_replace($defaults, $overrides);

    return new AdmissionController(
        sampler: $sampler,
        cpuHighPct: $cfg['cpuHighPct'],
        cpuLowPct: $cfg['cpuLowPct'],
        memoryHighPct: $cfg['memoryHighPct'],
        memoryLowPct: $cfg['memoryLowPct'],
        cooldownMs: $cfg['cooldownMs'],
        backoffMs: $cfg['backoffMs'],
        jitterMs: $cfg['jitterMs'],
        maxWaitSeconds: $cfg['maxWaitSeconds'],
        lockPath: $cfg['lockPath'],
        enabled: $cfg['enabled'],
        sleeper: $sleeper,
        clock: $clock,
    );
}

it('admits immediately when CPU and memory are both under the high watermark', function (): void {
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s->enqueue(30.0, 40.0),
        ['lockPath' => $this->lockPath],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($decision->reason)->toBe('immediate')
        ->and($decision->checks)->toBe(1)
        ->and($decision->waitedSeconds)->toBe(0.0)
        ->and($decision->cpuUsedPct)->toBe(30.0)
        ->and($decision->memoryUsedPct)->toBe(40.0);
});

it('admits immediately (fail-open) when both metrics are unmeasurable on this platform', function (): void {
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s->enqueue(null, null),
        ['lockPath' => $this->lockPath],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($decision->reason)->toBe('admitted-fail-open')
        ->and($decision->cpuUsedPct)->toBeNull()
        ->and($decision->memoryUsedPct)->toBeNull();
});

it('admits immediately when one metric is null and the other is under the high mark (per-metric fail-open)', function (): void {
    // Memory unmeasurable, CPU low → the memory branch fails open, CPU
    // gates on 30% (< 80% high) so we admit immediately.
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s->enqueue(30.0, null),
        ['lockPath' => $this->lockPath],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($decision->reason)->toBe('immediate');
});

it('queues while CPU is above the high mark and admits after pressure drops below the resume mark', function (): void {
    // Sequence: 3 samples over the high water mark, then one below the low
    // water mark. The controller should sleep between attempts and finally
    // admit under the resume-mark branch.
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s
            ->enqueue(90.0, 40.0)   // over high — queue
            ->enqueue(88.0, 40.0)   // still high — queue
            ->enqueue(82.0, 40.0)   // between low and high — still queued (hysteresis)
            ->enqueue(70.0, 40.0),  // below LOW resume mark — admit
        ['lockPath' => $this->lockPath],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($decision->reason)->toBe('admitted-below-resume-mark')
        ->and($decision->checks)->toBe(4)
        ->and($decision->waitedSeconds)->toBeGreaterThan(0.0);

    // 3 rounds of backoff between the 4 checks — no busy loop.
    expect($this->slept)->toBeGreaterThanOrEqual(0.030);   // 3 * 10ms
});

it('queues on high memory even when CPU is fine (memory-driven backpressure)', function (): void {
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s
            ->enqueue(20.0, 92.0)   // memory over high — queue
            ->enqueue(20.0, 70.0),  // memory below LOW — admit
        ['lockPath' => $this->lockPath],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($decision->checks)->toBe(2)
        ->and($decision->memoryUsedPct)->toBe(70.0);
});

it('honours hysteresis: after queuing, requires drop below LOW watermark to admit', function (): void {
    // Second sample is 78% CPU — below the 80% high mark but above the 75%
    // low mark. In a non-hysteresis controller this would admit; here it
    // must keep queuing because a prior sample already crossed high.
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s
            ->enqueue(90.0, 40.0)   // over high → queue-depth++
            ->enqueue(78.0, 40.0)   // between low and high → still queued
            ->enqueue(74.0, 40.0),  // below LOW → admit
        ['lockPath' => $this->lockPath],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($decision->checks)->toBe(3);
});

it('returns not-admitted (with reason=max-wait-elapsed) rather than deadlock when pressure never drops', function (): void {
    // Sampler returns high pressure forever.
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s->enqueue(95.0, 40.0),
        [
            'lockPath' => $this->lockPath,
            'maxWaitSeconds' => 2,      // small ceiling
            'backoffMs' => 500,
        ],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeFalse()
        ->and($decision->reason)->toBe('max-wait-elapsed')
        ->and($decision->waitedSeconds)->toBeGreaterThanOrEqual(2.0);
});

it('is a no-op when disabled — returns admitted with reason=disabled', function (): void {
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s->enqueue(99.0, 99.0),
        ['lockPath' => $this->lockPath, 'enabled' => false],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($decision->reason)->toBe('disabled')
        ->and($decision->checks)->toBe(0);
});

it('serializes admission across concurrent workers via flock (state file survives across controller instances)', function (): void {
    // Rehearse the cross-process scenario using two independent controller
    // instances (same lockPath). Worker A admits (writing state), then
    // Worker B constructs a fresh controller pointing at the same lock and
    // observes the same hysteresis state.
    $controllerA = makeController(
        fn (FakeResourceSampler $s) => $s->enqueue(90.0, 40.0),   // over high
        ['lockPath' => $this->lockPath, 'maxWaitSeconds' => 1, 'backoffMs' => 10],
        $this->clock,
        $this->sleeper,
    );

    $decisionA = $controllerA->admit();
    expect($decisionA->admitted)->toBeFalse()->and($decisionA->reason)->toBe('max-wait-elapsed');

    // The state file must show queue depth > 0.
    $state = json_decode((string) file_get_contents($this->lockPath.'.state'), true);
    expect($state)->toBeArray()
        ->and((int) ($state['queue_depth'] ?? 0))->toBeGreaterThan(0);

    // Worker B: also observes high pressure and queues (does NOT admit
    // just because it's a different PHP object).
    $controllerB = makeController(
        fn (FakeResourceSampler $s) => $s->enqueue(78.0, 40.0),  // between low and high
        ['lockPath' => $this->lockPath, 'maxWaitSeconds' => 1, 'backoffMs' => 10],
        $this->clock,
        $this->sleeper,
    );

    $decisionB = $controllerB->admit();
    expect($decisionB->admitted)->toBeFalse()   // must NOT admit: hysteresis in effect
        ->and($decisionB->reason)->toBe('max-wait-elapsed');
});

it('sleeps between admission attempts rather than busy-looping', function (): void {
    // With backoff 100ms and 5 high-pressure samples then a low one, the
    // sleeper must be called at least 5 times × 100ms = 500ms of slept time.
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s
            ->enqueue(95.0, 40.0)
            ->enqueue(95.0, 40.0)
            ->enqueue(95.0, 40.0)
            ->enqueue(95.0, 40.0)
            ->enqueue(95.0, 40.0)
            ->enqueue(60.0, 40.0),  // finally OK, and below LOW mark
        ['lockPath' => $this->lockPath, 'backoffMs' => 100, 'jitterMs' => 0, 'maxWaitSeconds' => 60],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($this->slept)->toBeGreaterThanOrEqual(0.5);
});

it('applies cooldown inside the lock so the next worker samples a settled system', function (): void {
    // With cooldown 200ms, an admitted worker must have slept for at least
    // 200ms before releasing the lock (before returning).
    $controller = makeController(
        fn (FakeResourceSampler $s) => $s->enqueue(20.0, 20.0),
        ['lockPath' => $this->lockPath, 'cooldownMs' => 200],
        $this->clock,
        $this->sleeper,
    );

    $decision = $controller->admit();

    expect($decision->admitted)->toBeTrue()
        ->and($this->slept)->toBeGreaterThanOrEqual(0.200);
});
