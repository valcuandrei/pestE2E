<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Output;

use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

/**
 * Clears per-test E2E entries when a test suite finishes.
 *
 * @internal
 */
final class TestSuiteFinishedSubscriber implements FinishedSubscriber
{
    /**
     * Handle the TestSuite Finished event.
     */
    public function notify(Finished $event): void
    {
        if (! function_exists('app')) {
            return;
        }

        /** @var E2EOutputStore $store */
        $store = app(E2EOutputStore::class);
        $entriesByTest = $store->getAllPerTestEntries();

        if ($entriesByTest === []) {
            return;
        }

        foreach ($entriesByTest as $entries) {
            foreach ($entries as $entry) {
                $lines = $entry->lines;
                $counter = count($lines);

                if ($counter < 2) {
                    continue;
                }

                for ($i = 1; $i < $counter; $i++) {
                    fwrite(STDOUT, $lines[$i]."\n");
                }

                fwrite(STDOUT, "\n");
            }
        }

        $store->flushPerTestEntries();
    }
}
