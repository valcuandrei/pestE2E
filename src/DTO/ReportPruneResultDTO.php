<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * Outcome of one pruning pass.
 *
 * @internal
 */
final class ReportPruneResultDTO
{
    /** Runs pest-e2e can prove it owns (registry record or legacy marker). */
    public int $owned = 0;

    /** Owned runs skipped because another process holds their lock. */
    public int $active = 0;

    /** Owned runs skipped because they are inside the grace period. */
    public int $inGrace = 0;

    /**
     * Report directories deleted (or, in a dry run, that would be).
     *
     * @var list<string>
     */
    public array $pruned = [];

    /** Runtime directories removed from runs that are no longer active. */
    public int $staleRuntimeRemoved = 0;

    /** Unmarked directories found beside owned runs. */
    public int $legacyFound = 0;

    /**
     * Unmarked directories that passed validation (deleted when legacy deletion was requested).
     *
     * @var list<string>
     */
    public array $legacyEligible = [];

    /**
     * Unmarked directories that were refused, with the reason.
     *
     * @var array<string, string>
     */
    public array $legacyRefused = [];

    /** True when the pass stopped at its deletion limit with work left. */
    public bool $truncated = false;

    /**
     * Directories that could not be removed.
     *
     * @var list<string>
     */
    public array $failed = [];

    /** Why the pass did not run at all, when it did not. */
    public ?string $skippedReason = null;
}
