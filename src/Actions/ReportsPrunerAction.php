<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Actions;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * @internal
 */
final class ReportsPrunerAction
{
    /**
     * Prune the reports directory by deleting old run directories.
     *
     * @throws InvalidArgumentException
     */
    public function handle(
        ?string $reportsDir = null,
        ?string $unit = null,
        ?int $value = null,
        bool $all = false,
        bool $dryRun = false,
    ): int {
        if (! config('pest-e2e.reports.prune.enabled')) {
            return 0;
        }

        $reportsDir ??= $this->configString('pest-e2e.reports.dir', storage_path('framework/testing/pest-e2e'));
        $unit ??= $this->configString('pest-e2e.reports.prune.unit', 'days');
        $value ??= $this->configInt('pest-e2e.reports.prune.value', 30);

        if (! File::isDirectory($reportsDir)) {
            return 0;
        }

        if (! in_array($unit, ['days', 'items'], true)) {
            throw new InvalidArgumentException("Invalid unit: {$unit}. Valid units are: days, items.");
        }

        if ($value < 0) {
            throw new InvalidArgumentException("Invalid value: {$value}. Value must be >= 0.");
        }

        /** @var string[] $dirs */
        $dirs = File::directories($reportsDir);

        if ($all) {
            foreach ($dirs as $dir) {
                if (! $dryRun) {
                    File::deleteDirectory($dir);
                }
            }

            return count($dirs);
        }

        // newest first
        usort(
            $dirs,
            static fn (string $a, string $b): int => File::lastModified($b) <=> File::lastModified($a)
        );

        $toDelete = [];

        if ($unit === 'items') {
            // Keep newest $value, delete the rest
            $toDelete = array_slice($dirs, $value);
        } else {
            $threshold = now()->subDays($value)->getTimestamp();

            foreach ($dirs as $dir) {
                if (File::lastModified($dir) < $threshold) {
                    $toDelete[] = $dir;
                }
            }
        }

        foreach ($toDelete as $dir) {
            if (! $dryRun) {
                File::deleteDirectory($dir);
            }
        }

        return count($toDelete);
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function configInt(string $key, int $default = 0): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }
}
