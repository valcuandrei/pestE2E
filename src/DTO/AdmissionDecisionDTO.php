<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

use ValcuAndrei\PestE2E\Support\AdmissionController;

/**
 * Outcome of a single call to
 * {@see AdmissionController::admit()}.
 *
 * The DTO is small enough to log at the diagnostic layer without spamming;
 * `reason` is human-readable and safe for the pest output stream.
 *
 * @internal
 */
final readonly class AdmissionDecisionDTO
{
    /**
     * @param  bool  $admitted  True iff the caller was admitted (either immediately or after waiting).
     *                          False only when the max-wait ceiling elapsed while pressure was still high.
     * @param  int  $checks  Number of admission-check iterations before the decision.
     * @param  float  $waitedSeconds  Wall-clock time the caller spent waiting inside admit().
     * @param  float|null  $cpuUsedPct  Whole-system CPU busy percentage observed at admission (or last check).
     * @param  float|null  $memoryUsedPct  Memory used percentage observed at admission (or last check).
     * @param  string  $reason  Short label for the decision path:
     *                          'immediate' | 'admitted-after-wait' | 'admitted-fail-open' |
     *                          'admitted-cooldown-elapsed' | 'admitted-below-resume-mark' |
     *                          'max-wait-elapsed' | 'disabled'
     * @param  array<string, int|float|string|bool|null>  $diagnostics
     */
    public function __construct(
        public bool $admitted,
        public int $checks,
        public float $waitedSeconds,
        public ?float $cpuUsedPct,
        public ?float $memoryUsedPct,
        public string $reason,
        public array $diagnostics = [],
    ) {}
}
