<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\Contracts\ResourceSamplerContract;
use ValcuAndrei\PestE2E\DTO\AdmissionDecisionDTO;

/**
 * Adaptive resource-based admission controller for pest-e2e Playwright
 * invocations.
 *
 * Purpose
 * -------
 * Under `--parallel`, N Pest workers can each start an E2E test at the same
 * moment. If N is large enough to saturate the host, Playwright's own 5-second
 * action timeouts start firing on a random subset of workers because a busy
 * DOM/render step slips past the ceiling. F1 (--workers=1), F2 (native-fetch
 * auth) and F5 (warm browser) each removed a source of per-invocation
 * overhead but they cannot bound the *steady-state* pressure — 12 warm
 * browsers can still all execute a heavyweight test simultaneously.
 *
 * This controller sits in front of every heavyweight E2E execution. Each
 * worker asks "am I allowed to run now?" and, if not, waits (sleeping, no
 * busy loop) until pressure drops. Fast hardware naturally admits more
 * concurrent work; slow hardware naturally queues earlier. No fixed
 * concurrency cap is ever encoded.
 *
 * Serialisation
 * -------------
 * All workers contend for a single project-scoped exclusive flock:
 *
 *     <sys_get_temp_dir>/pest-e2e-admission-<sha1(project)>.lock
 *
 * The lock protects the "sample → decide → cooldown" critical section so
 * twelve workers cannot simultaneously observe 60 % CPU and all admit. OS
 * `flock` was chosen over PID-marker files because the lock disappears
 * automatically when a process dies — no stale-state cleanup needed.
 *
 * Hysteresis
 * ----------
 * Two watermarks per resource. If neither metric is above the high
 * watermark, admit. If one is, release the lock, back off with jitter, and
 * retry. To avoid oscillation around the high mark, once any worker has
 * queued, subsequent workers require pressure to drop below the *low*
 * (resume) mark before they may admit. That "someone-queued" state lives in
 * a sibling state file (`<lock>.state`) so it survives across worker
 * processes for the length of the admission session.
 *
 * Cooldown
 * --------
 * When a worker is admitted, the controller writes the current unix time to
 * the state file and sleeps for `cooldown_ms` before releasing the lock.
 * The next worker to acquire the lock will therefore sample a system where
 * the previous admission has had time to load. This is the single biggest
 * dampening effect against stampedes.
 *
 * Fail-open
 * ---------
 * A metric that comes back as `null` (unmeasurable on this platform) is
 * treated as "not above threshold". If BOTH metrics are null the admission
 * path collapses to an immediate admit — pest-e2e never deadlocks on a
 * platform it can't sample.
 *
 * @internal
 */
final class AdmissionController
{
    private const STATE_LAST_ADMIT_TS = 'last_admit_ts';

    private const STATE_QUEUE_DEPTH = 'queue_depth';

    /**
     * @param  callable(float): void|null  $sleeper  Optional injectable sleeper; tests replace usleep() to avoid
     *                                               real wall-clock waits.
     * @param  callable(): float|null  $clock  Optional injectable clock; tests replace microtime(true).
     */
    public function __construct(
        private readonly ResourceSamplerContract $sampler,
        private readonly float $cpuHighPct,
        private readonly float $cpuLowPct,
        private readonly float $memoryHighPct,
        private readonly float $memoryLowPct,
        private readonly int $cooldownMs,
        private readonly int $backoffMs,
        private readonly int $jitterMs,
        private readonly int $maxWaitSeconds,
        private readonly ?string $lockPath = null,
        private readonly bool $enabled = true,
        $sleeper = null,
        $clock = null,
    ) {
        $this->sleeper = $sleeper ?? (static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) ($seconds * 1_000_000));
            }
        });
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** @var callable(float): void */
    private $sleeper;

    /** @var callable(): float */
    private $clock;

    /**
     * Request permission to start a heavyweight E2E execution. Blocks until
     * either the host is under pressure limits or the max-wait ceiling
     * elapses. Returns the decision either way; the caller must honour it
     * (proceed if admitted, surface a diagnostic if not).
     */
    public function admit(): AdmissionDecisionDTO
    {
        if (! $this->enabled) {
            return new AdmissionDecisionDTO(
                admitted: true,
                checks: 0,
                waitedSeconds: 0.0,
                cpuUsedPct: null,
                memoryUsedPct: null,
                reason: 'disabled',
            );
        }

        $lockPath = $this->lockPath ?? $this->defaultLockPath();
        $statePath = $lockPath.'.state';
        $startedAt = ($this->clock)();
        $checks = 0;
        $lastSample = null;

        while (true) {
            $checks++;

            $decision = $this->attempt($lockPath, $statePath, $checks, $startedAt);

            if ($decision instanceof AdmissionDecisionDTO) {
                return $decision;
            }

            // Not admitted this round — sleep backoff + jitter and retry.
            $lastSample = null;  // sampler will produce a fresh one next iteration
            $sleepSeconds = ($this->backoffMs + random_int(0, max(0, $this->jitterMs))) / 1000.0;
            ($this->sleeper)($sleepSeconds);
        }
    }

    /**
     * One admission attempt inside the flock. Returns a decision to end the
     * loop, or null to indicate "retry after backoff".
     */
    private function attempt(string $lockPath, string $statePath, int $checks, float $startedAt): ?AdmissionDecisionDTO
    {
        $lockHandle = $this->acquireLock($lockPath);

        if ($lockHandle === false) {
            // Rare: the lock file could not be created (permissions, tmpfs
            // full). Fail-open — do not deadlock.
            return $this->admittedNow(
                $checks,
                $startedAt,
                null,
                null,
                'admitted-fail-open',
                ['lock_unavailable' => true],
            );
        }

        try {
            $state = $this->readState($statePath);
            $sample = $this->sampler->sample();

            $cpuOverHigh = $sample->cpuUsedPct !== null && $sample->cpuUsedPct >= $this->cpuHighPct;
            $memOverHigh = $sample->memoryUsedPct !== null && $sample->memoryUsedPct >= $this->memoryHighPct;
            $anythingMeasurable = $sample->cpuUsedPct !== null || $sample->memoryUsedPct !== null;

            if (! $anythingMeasurable) {
                // Both metrics unavailable → controller no-ops on this platform.
                $this->markAdmit($statePath, $state);

                return $this->admittedNow(
                    $checks,
                    $startedAt,
                    null,
                    null,
                    'admitted-fail-open',
                    $sample->diagnostics,
                );
            }

            $waited = ($this->clock)() - $startedAt;
            $queueDepth = (int) ($state[self::STATE_QUEUE_DEPTH] ?? 0);
            $inHysteresisMode = $queueDepth > 0;

            // Max-wait ceiling. Fires whenever the caller is still being
            // queued after `maxWaitSeconds`, whether the sample is over the
            // high-water mark or stuck between low and high with hysteresis
            // active. Rather than deadlock a test suite, return a
            // not-admitted decision so the caller can log the soft-limit
            // breach and continue.
            $inHysteresisAndStillHigh = $inHysteresisMode && (
                ($sample->cpuUsedPct !== null && $sample->cpuUsedPct >= $this->cpuLowPct)
                || ($sample->memoryUsedPct !== null && $sample->memoryUsedPct >= $this->memoryLowPct)
            );

            if ($this->maxWaitSeconds > 0 && $waited >= $this->maxWaitSeconds && ($cpuOverHigh || $memOverHigh || $inHysteresisAndStillHigh)) {
                return new AdmissionDecisionDTO(
                    admitted: false,
                    checks: $checks,
                    waitedSeconds: $waited,
                    cpuUsedPct: $sample->cpuUsedPct,
                    memoryUsedPct: $sample->memoryUsedPct,
                    reason: 'max-wait-elapsed',
                    diagnostics: $sample->diagnostics,
                );
            }

            if ($cpuOverHigh || $memOverHigh) {
                // Over a high-water mark: not admitted, tick queue depth so
                // hysteresis mode kicks in for subsequent workers.
                $state[self::STATE_QUEUE_DEPTH] = $queueDepth + 1;
                $this->writeState($statePath, $state);

                return null;
            }

            if ($inHysteresisMode) {
                $cpuAcceptable = $sample->cpuUsedPct === null || $sample->cpuUsedPct < $this->cpuLowPct;
                $memAcceptable = $sample->memoryUsedPct === null || $sample->memoryUsedPct < $this->memoryLowPct;

                if (! $cpuAcceptable || ! $memAcceptable) {
                    return null;
                }

                // Pressure dropped below the low-water resume marks. Reset
                // hysteresis and admit.
                $state[self::STATE_QUEUE_DEPTH] = 0;
                $this->markAdmit($statePath, $state);

                return $this->admittedNow(
                    $checks,
                    $startedAt,
                    $sample->cpuUsedPct,
                    $sample->memoryUsedPct,
                    'admitted-below-resume-mark',
                    $sample->diagnostics,
                );
            }

            // Below the high watermarks and nobody is currently queued —
            // classic immediate-admission path.
            $this->markAdmit($statePath, $state);

            return $this->admittedNow(
                $checks,
                $startedAt,
                $sample->cpuUsedPct,
                $sample->memoryUsedPct,
                $checks === 1 ? 'immediate' : 'admitted-after-wait',
                $sample->diagnostics,
            );
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $diag
     */
    private function admittedNow(int $checks, float $startedAt, ?float $cpu, ?float $mem, string $reason, array $diag): AdmissionDecisionDTO
    {
        // Cooldown INSIDE the critical section — the next worker to grab the
        // lock will therefore sample a system that has had `cooldown_ms`
        // to react to our admission. This is the strongest single defence
        // against 12-way stampede.
        if ($this->cooldownMs > 0) {
            ($this->sleeper)($this->cooldownMs / 1000.0);
        }

        return new AdmissionDecisionDTO(
            admitted: true,
            checks: $checks,
            waitedSeconds: ($this->clock)() - $startedAt,
            cpuUsedPct: $cpu,
            memoryUsedPct: $mem,
            reason: $reason,
            diagnostics: $diag,
        );
    }

    /**
     * @return resource|false
     */
    private function acquireLock(string $lockPath)
    {
        $dir = dirname($lockPath);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }

        $handle = @fopen($lockPath, 'c');

        if ($handle === false) {
            return false;
        }

        if (! @flock($handle, LOCK_EX)) {
            @fclose($handle);

            return false;
        }

        return $handle;
    }

    /**
     * @return array<string, int|float|string|bool>
     */
    private function readState(string $statePath): array
    {
        if (! is_file($statePath)) {
            return [];
        }

        $contents = @file_get_contents($statePath);

        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, int|float|string|bool> $filtered */
        $filtered = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && (is_int($v) || is_float($v) || is_string($v) || is_bool($v))) {
                $filtered[$k] = $v;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, int|float|string|bool>  $state
     */
    private function writeState(string $statePath, array $state): void
    {
        $dir = dirname($statePath);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return;
        }

        @file_put_contents($statePath, json_encode($state), LOCK_EX);
    }

    /**
     * @param  array<string, int|float|string|bool>  $state
     */
    private function markAdmit(string $statePath, array $state): void
    {
        $state[self::STATE_LAST_ADMIT_TS] = ($this->clock)();
        $this->writeState($statePath, $state);
    }

    private function defaultLockPath(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'pest-e2e-admission-'.ReportPathPolicy::projectFingerprint()
            .'-'.ReportPathPolicy::effectiveUserId()
            .'.lock';
    }
}
