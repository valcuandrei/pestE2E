<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

/**
 * What one pruning pass is allowed to do.
 *
 * @internal
 */
final readonly class ReportPruneOptionsDTO
{
    /**
     * @param  int  $keepRuns  Keep at most this many newest runs; 0 turns the count rule off.
     * @param  int  $keepDays  Keep runs for at most this many days; 0 turns the age rule off.
     * @param  int  $graceSeconds  Never delete a run that ended (or was allocated) less than this long ago.
     * @param  bool  $retention  False limits the pass to removing stale runtime files.
     * @param  bool  $all  Delete every owned, inactive run regardless of the count and age rules.
     * @param  bool  $dryRun  Report what would be deleted without deleting anything.
     * @param  bool  $inspectLegacy  Validate unmarked directories and report them.
     * @param  bool  $deleteLegacy  Delete unmarked directories that pass validation.
     * @param  int|null  $maxDeletions  Stop after this many run directories; null for no limit.
     * @param  list<string>  $protectedReportDirectories  Report directories that must never be deleted (the caller's own run).
     */
    public function __construct(
        public int $keepRuns = 500,
        public int $keepDays = 7,
        public int $graceSeconds = 3600,
        public bool $retention = true,
        public bool $all = false,
        public bool $dryRun = false,
        public bool $inspectLegacy = false,
        public bool $deleteLegacy = false,
        public ?int $maxDeletions = null,
        public array $protectedReportDirectories = [],
        public ?int $now = null,
    ) {}
}
