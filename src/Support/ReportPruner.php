<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use ValcuAndrei\PestE2E\DTO\ReportPruneOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ReportPruneResultDTO;

/**
 * Deletes old E2E run directories from a report base directory.
 *
 * Used by the automatic pre-launch sweep and by `pest-e2e:prune-reports`,
 * so both follow the same rules:
 *
 * - Only runs pest-e2e can prove it owns are ever deleted automatically: a
 *   registry record (see {@see ReportRunRegistry}) or the `.pest-e2e-run`
 *   marker written by earlier versions.
 * - A run whose lock is held by another process is active and is skipped.
 *   The lock is held while the run's directories are deleted, so a run
 *   cannot start or be deleted twice in between.
 * - A run inside the grace period is skipped.
 * - Otherwise a run is deleted when it is older than `keepDays` OR is not
 *   among the newest `keepRuns` owned runs. Either rule alone is enough; a
 *   rule set to 0 is off.
 * - Symbolic links are never followed, and nothing outside the base
 *   directory is touched.
 *
 * Directories with no proof of ownership are only counted, unless legacy
 * handling is requested explicitly.
 *
 * @internal
 */
final readonly class ReportPruner
{
    public const string LEGACY_MARKER = '.pest-e2e-run';

    /**
     * Playwright writes this into every output directory; an unmarked
     * directory must have it (or be empty) to be treated as an old run.
     */
    private const string PLAYWRIGHT_LAST_RUN_FILE = '.last-run.json';

    /**
     * A record must be at least this old before an unlocked run's runtime
     * files are treated as abandoned, so a run that is just being allocated
     * is never caught between creating its record and locking it.
     */
    private const int STALE_RUNTIME_SECONDS = 60;

    public function __construct(
        private ReportRunRegistry $registry = new ReportRunRegistry,
    ) {}

    /**
     * Deletion boundaries, all enforced by {@see SafeDirectoryRemover::removeTreeInside()}:
     * report and legacy directories must be real directories exactly two
     * levels below the base (`<base>/<target>/<run>`); registry and runtime
     * directories must be inside `<base>/.pest-e2e-state`. A base directory
     * that is itself a symbolic link is refused outright.
     */
    public function prune(string $baseDirectory, ReportPruneOptionsDTO $options): ReportPruneResultDTO
    {
        $result = new ReportPruneResultDTO;
        $baseDirectory = rtrim($baseDirectory, '/');

        if ($baseDirectory === '' || ! is_dir($baseDirectory)) {
            $result->skippedReason = 'the report directory does not exist';

            return $result;
        }

        if (is_link($baseDirectory)) {
            $result->skippedReason = 'the report directory is a symbolic link';

            return $result;
        }

        $now = $options->now ?? time();
        $registered = $this->registry->registeredRuns($baseDirectory);

        $this->removeStaleRuntime($baseDirectory, $registered, $options, $now, $result);

        if (! $options->retention && ! $options->all && ! $options->inspectLegacy && ! $options->deleteLegacy) {
            return $result;
        }

        [$runs, $unmarked] = $this->collect($baseDirectory, $registered);
        $result->owned = count($runs);
        $result->legacyFound = count($unmarked);

        if ($options->retention || $options->all) {
            $this->pruneOwned($baseDirectory, $runs, $options, $now, $result);
        }

        if ($options->inspectLegacy || $options->deleteLegacy) {
            $this->handleLegacy($baseDirectory, $unmarked, $options, $now, $result);
        }

        return $result;
    }

    /**
     * @param  list<array{target: string, run: string, stateDirectory: string, recordedAt: int}>  $registered
     */
    private function removeStaleRuntime(string $baseDirectory, array $registered, ReportPruneOptionsDTO $options, int $now, ReportPruneResultDTO $result): void
    {
        foreach ($registered as $run) {
            $runtime = $run['stateDirectory'].'/'.ReportRunRegistry::RUNTIME_DIRECTORY;

            if (! is_dir($runtime) || $now - $run['recordedAt'] < self::STALE_RUNTIME_SECONDS) {
                continue;
            }

            $lock = $this->registry->lockIfInactive($run['stateDirectory']);

            if ($lock === null) {
                continue;
            }

            if ($options->dryRun || SafeDirectoryRemover::removeTreeInside($runtime, ReportRunRegistry::stateRoot($baseDirectory))) {
                $result->staleRuntimeRemoved++;
            }

            $this->registry->unlock($lock);
        }
    }

    /**
     * Split the run directories of a base directory into owned runs and unmarked directories.
     *
     * @param  list<array{target: string, run: string, stateDirectory: string, recordedAt: int}>  $registered
     * @return array{list<array{reportDirectory: string, stateDirectory: string|null, time: int}>, list<string>}
     */
    private function collect(string $baseDirectory, array $registered): array
    {
        $runs = [];
        $known = [];

        foreach ($registered as $run) {
            $reportDirectory = $baseDirectory.'/'.$run['target'].'/'.$run['run'];
            $known[$reportDirectory] = true;
            $runs[] = ['reportDirectory' => $reportDirectory, 'stateDirectory' => $run['stateDirectory'], 'time' => $run['recordedAt']];
        }

        $unmarked = [];

        foreach ($this->registry->realSubdirectories($baseDirectory) as $target) {
            if ($target === ReportRunRegistry::STATE_DIRECTORY) {
                continue;
            }

            foreach ($this->registry->realSubdirectories($baseDirectory.'/'.$target) as $run) {
                $reportDirectory = $baseDirectory.'/'.$target.'/'.$run;

                if (isset($known[$reportDirectory])) {
                    continue;
                }

                $marker = $reportDirectory.'/'.self::LEGACY_MARKER;

                if (is_file($marker) && ! is_link($marker)) {
                    $runs[] = ['reportDirectory' => $reportDirectory, 'stateDirectory' => null, 'time' => @filemtime($marker) ?: 0];
                } else {
                    $unmarked[] = $reportDirectory;
                }
            }
        }

        usort($runs, static fn (array $left, array $right): int => $right['time'] <=> $left['time']);

        return [$runs, $unmarked];
    }

    /**
     * @param  list<array{reportDirectory: string, stateDirectory: string|null, time: int}>  $runs  Newest first.
     */
    private function pruneOwned(string $baseDirectory, array $runs, ReportPruneOptionsDTO $options, int $now, ReportPruneResultDTO $result): void
    {
        $oldestAllowed = $options->keepDays > 0 ? $now - ($options->keepDays * 86400) : null;
        $protected = array_flip(array_map(static fn (string $path): string => rtrim($path, '/'), $options->protectedReportDirectories));

        foreach ($runs as $index => $run) {
            if (isset($protected[$run['reportDirectory']])) {
                continue;
            }

            $tooMany = $options->keepRuns > 0 && $index >= $options->keepRuns;
            $tooOld = $oldestAllowed !== null && $run['time'] < $oldestAllowed;

            if (! $options->all && ! $tooMany && ! $tooOld) {
                continue;
            }

            // A run without a registry record has no lock to prove it is
            // idle, so the grace period is its only active-run protection
            // and applies even to an explicit "delete all".
            $graceApplies = ! $options->all || $run['stateDirectory'] === null;

            if ($graceApplies && $now - $run['time'] < $options->graceSeconds) {
                $result->inGrace++;

                continue;
            }

            if ($options->maxDeletions !== null && count($result->pruned) >= $options->maxDeletions) {
                $result->truncated = true;

                return;
            }

            $lock = null;

            if ($run['stateDirectory'] !== null) {
                $lock = $this->registry->lockIfInactive($run['stateDirectory']);

                if ($lock === null) {
                    $result->active++;

                    continue;
                }
            }

            $this->deleteRun($baseDirectory, $run['reportDirectory'], $run['stateDirectory'], $options->dryRun, $result);

            if ($lock !== null) {
                $this->registry->unlock($lock);
            }
        }
    }

    private function deleteRun(string $baseDirectory, string $reportDirectory, ?string $stateDirectory, bool $dryRun, ReportPruneResultDTO $result): void
    {
        $reportExists = file_exists($reportDirectory) || is_link($reportDirectory);

        if ($reportExists && ! $this->isRunDirectoryOf($reportDirectory, $baseDirectory)) {
            $result->failed[] = $reportDirectory;

            return;
        }

        if ($dryRun) {
            $result->pruned[] = $reportDirectory;

            return;
        }

        if ($reportExists && ! SafeDirectoryRemover::removeTreeInside($reportDirectory, $baseDirectory)) {
            $result->failed[] = $reportDirectory;

            return;
        }

        if ($stateDirectory !== null) {
            SafeDirectoryRemover::removeTreeInside($stateDirectory, ReportRunRegistry::stateRoot($baseDirectory));
        }

        $result->pruned[] = $reportDirectory;
    }

    /**
     * True when $directory is a real directory at exactly `<base>/<target>/<run>`.
     */
    private function isRunDirectoryOf(string $directory, string $baseDirectory): bool
    {
        if (! SafeDirectoryRemover::isInside($directory, $baseDirectory)) {
            return false;
        }

        return dirname((string) realpath($directory), 2) === realpath($baseDirectory);
    }

    /**
     * @param  list<string>  $unmarked
     */
    private function handleLegacy(string $baseDirectory, array $unmarked, ReportPruneOptionsDTO $options, int $now, ReportPruneResultDTO $result): void
    {
        foreach ($unmarked as $directory) {
            $problem = $this->legacyProblem($baseDirectory, $directory, $options, $now);

            if ($problem !== null) {
                $result->legacyRefused[$directory] = $problem;

                continue;
            }

            if ($options->deleteLegacy && ! $options->dryRun && ! SafeDirectoryRemover::removeTreeInside($directory, $baseDirectory)) {
                $result->failed[] = $directory;

                continue;
            }

            $result->legacyEligible[] = $directory;
        }
    }

    /**
     * Why an unmarked directory is not accepted as an old pest-e2e run, or null when it is.
     */
    private function legacyProblem(string $baseDirectory, string $directory, ReportPruneOptionsDTO $options, int $now): ?string
    {
        if (! $this->isRunDirectoryOf($directory, $baseDirectory)) {
            return 'is not a real run directory of the report directory';
        }

        $entries = array_values(array_diff(@scandir($directory) ?: [], ['.', '..']));

        if ($entries !== [] && ! in_array(self::PLAYWRIGHT_LAST_RUN_FILE, $entries, true)) {
            return 'does not look like a Playwright output directory (no '.self::PLAYWRIGHT_LAST_RUN_FILE.')';
        }

        $unexpected = SafeDirectoryRemover::unexpectedContent($directory);

        if ($unexpected !== null) {
            return $unexpected;
        }

        $newest = @filemtime($directory) ?: 0;

        foreach ($entries as $entry) {
            $newest = max($newest, @filemtime($directory.'/'.$entry) ?: 0);
        }

        if ($now - $newest < $options->graceSeconds) {
            return 'was modified within the grace period and may belong to a run in progress';
        }

        return null;
    }
}
