<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use ValcuAndrei\PestE2E\Actions\ReportsPrunerAction;

/**
 * @internal
 */
class PruneReportsCommand extends Command
{
    protected $signature = 'pest-e2e:prune-reports'
        .' {--unit=days : The unit of time to prune by (days, items)}'
        .' {--value=30 : The value of the unit to prune by}'
        .' {--all : Prune all reports}'
        .' {--dry-run : Show what would be deleted without actually deleting anything}';

    protected $description = 'Prune the reports directory by deleting old run directories.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $unitRaw = $this->option('unit');
        $unit = is_string($unitRaw) ? $unitRaw : 'days';
        $valueRaw = $this->option('value');
        $value = is_int($valueRaw) ? $valueRaw : (is_numeric($valueRaw) ? (int) $valueRaw : 30);
        $msg = $unit === 'days'
            ? "Pruning reports older than {$value} days..."
            : "Keeping the {$value} newest reports...";

        if ($dryRun) {
            $this->warn('This is a dry run. No reports will be deleted.');
        }
        $this->info(($all ? 'Pruning all reports...' : $msg));

        try {
            $deletedCount = app(ReportsPrunerAction::class)->handle(
                unit: $unit,
                value: $value,
                all: $all,
                dryRun: $dryRun,
            );

            $this->info(($dryRun ? 'Would delete ' : 'Deleted ').$deletedCount.' reports.');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
