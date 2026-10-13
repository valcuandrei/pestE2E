<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Commands;

use Illuminate\Console\Command;
use ValcuAndrei\PestE2E\DTO\ReportPruneOptionsDTO;
use ValcuAndrei\PestE2E\DTO\ReportPruneResultDTO;
use ValcuAndrei\PestE2E\Support\ReportDirectoryManager;
use ValcuAndrei\PestE2E\Support\ReportPruner;

/**
 * Manual counterpart of the automatic pre-launch sweep.
 *
 * Uses the same {@see ReportPruner}, so the ownership, active-run and symlink
 * rules are identical. It only ever works inside the configured report
 * directory.
 */
final class PruneReportsCommand extends Command
{
    protected $signature = 'pest-e2e:prune-reports'
        .' {--unit= : Prune by "days" (older than --value days) or "items" (keep the newest --value runs). Default: both rules from config}'
        .' {--value= : The number of days or runs for --unit}'
        .' {--all : Delete every run pest-e2e owns that is not active, ignoring retention and the grace period}'
        .' {--legacy : Also inspect unmarked directories left by older versions and report which could be removed}'
        .' {--delete-unmarked-legacy-runs : Delete the unmarked directories that pass validation}'
        .' {--dry-run : Show what would be deleted without deleting anything}';

    protected $description = 'Delete old E2E run directories from the pest-e2e report directory.';

    public function handle(ReportDirectoryManager $reports, ReportPruner $pruner): int
    {
        $options = $this->resolveOptions();

        if (! $options instanceof ReportPruneOptionsDTO) {
            return self::FAILURE;
        }

        $baseDirectory = $reports->baseDirectory();
        $this->line("Report directory: {$baseDirectory}");

        if ($options->dryRun) {
            $this->warn('Dry run: nothing will be deleted.');
        }

        $result = $pruner->prune($baseDirectory, $options);

        if ($result->skippedReason !== null) {
            $this->warn("Nothing to do: {$result->skippedReason}.");

            return self::SUCCESS;
        }

        $this->report($result, $options);

        return $result->failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveOptions(): ?ReportPruneOptionsDTO
    {
        $unit = $this->option('unit');
        $value = $this->option('value');
        $keepRuns = $this->configInt('pest-e2e.reports.prune.keep_runs', 500);
        $keepDays = $this->configInt('pest-e2e.reports.prune.keep_days', 7);

        if ($unit !== null || $value !== null) {
            if (! in_array($unit, ['days', 'items'], true)) {
                $this->error('Invalid --unit. Valid units are: days, items.');

                return null;
            }

            if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
                $this->error('--value must be a whole number, zero or greater, and is required with --unit.');

                return null;
            }

            // One explicit rule replaces both configured rules.
            $keepDays = $unit === 'days' ? (int) $value : 0;
            $keepRuns = $unit === 'items' ? (int) $value : 0;

            if ($keepDays === 0 && $keepRuns === 0 && ! $this->option('all')) {
                $this->error('--value=0 would keep nothing. Use --all to delete every inactive run.');

                return null;
            }
        }

        $deleteLegacy = (bool) $this->option('delete-unmarked-legacy-runs');

        return new ReportPruneOptionsDTO(
            keepRuns: $keepRuns,
            keepDays: $keepDays,
            graceSeconds: $this->configInt('pest-e2e.reports.prune.grace_minutes', 60) * 60,
            all: (bool) $this->option('all'),
            dryRun: (bool) $this->option('dry-run'),
            inspectLegacy: (bool) $this->option('legacy') || $deleteLegacy,
            deleteLegacy: $deleteLegacy,
        );
    }

    private function report(ReportPruneResultDTO $result, ReportPruneOptionsDTO $options): void
    {
        $verb = $options->dryRun ? 'Would delete' : 'Deleted';

        $this->line("Runs owned by pest-e2e: {$result->owned}");
        $this->line("Skipped, active: {$result->active}");
        $this->line("Skipped, inside the grace period: {$result->inGrace}");
        $this->info("{$verb} ".count($result->pruned).' run(s).');

        if ($result->staleRuntimeRemoved > 0) {
            $this->line(($options->dryRun ? 'Would remove' : 'Removed')." stale runtime files of {$result->staleRuntimeRemoved} finished run(s).");
        }

        if ($options->dryRun) {
            foreach ($result->pruned as $directory) {
                $this->line("  {$directory}");
            }
        }

        $this->line("Unmarked directories (not owned): {$result->legacyFound}");

        if ($options->inspectLegacy) {
            $legacyVerb = $options->deleteLegacy ? $verb : 'Could delete with --delete-unmarked-legacy-runs:';
            $this->info("{$legacyVerb} ".count($result->legacyEligible).' unmarked legacy run(s).');
            $this->line('Refused: '.count($result->legacyRefused));

            foreach (array_slice($result->legacyRefused, 0, 20, true) as $directory => $reason) {
                $this->line("  {$directory} — {$reason}");
            }

            if (count($result->legacyRefused) > 20) {
                $this->line('  … and '.(count($result->legacyRefused) - 20).' more');
            }
        } elseif ($result->legacyFound > 0) {
            $this->line('  Never deleted automatically. Use --legacy to inspect them.');
        }

        foreach ($result->failed as $directory) {
            $this->error("Could not remove: {$directory}");
        }
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return max(0, $value);
        }

        return is_string($value) && is_numeric($value) ? max(0, (int) $value) : $default;
    }
}
