<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E;

use Pest\Collision\Events;
use Pest\Contracts\Plugins\AddsOutput;
use Pest\Contracts\Plugins\Terminable;
use Symfony\Component\Console\Output\OutputInterface;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

/**
 * @internal
 */
final class Plugin implements AddsOutput, Terminable
{
    /**
     * Creates a new Plugin instance.
     */
    public function __construct(
        private readonly OutputInterface $output,
    ) {
        if (class_exists(Events::class)) {
            Events::setOutput($output);
        }
    }

    /**
     * Get the E2E output store from the Laravel container.
     * We cannot use constructor injection because Pest creates plugins
     * via its own container, which would create a separate E2EOutputStore instance.
     */
    private function store(): E2EOutputStore
    {
        return app(E2EOutputStore::class);
    }

    /**
     * {@inheritdoc}
     */
    public function addOutput(int $exitCode): int
    {
        $store = $this->store();

        // Print per-test entries inline (without repeating parent test line)
        $perTestEntries = $store->getAllPerTestEntries();

        if ($perTestEntries !== []) {
            $lines = [];

            foreach ($perTestEntries as $entries) {
                foreach ($entries as $entry) {
                    $storedLines = $entry->lines;
                    $counter = count($storedLines);

                    if ($counter < 2) {
                        continue;
                    }

                    for ($i = 1; $i < $counter; $i++) {
                        $lines[] = $storedLines[$i];
                    }

                    $lines[] = '';
                }
            }

            if ($lines !== []) {
                $this->output->writeln($lines);
            }
        }

        $store->flushPerTestEntries();

        // Print any orphaned entries (fallback for entries not associated with a test)
        $entries = $store->flush();

        if ($entries !== []) {
            $lines = [];
            $currentParent = null;
            $hasOutput = false;

            foreach ($entries as $entry) {
                $grouped = $this->splitGroupedLines($entry->lines);

                if ($grouped !== null) {
                    [$parent, $childLines] = $grouped;

                    if ($currentParent !== $parent) {
                        if ($hasOutput) {
                            $lines[] = '';
                        }

                        $lines[] = $parent;
                        $currentParent = $parent;
                    }

                    foreach ($childLines as $line) {
                        $lines[] = $line;
                    }

                    $hasOutput = true;

                    continue;
                }

                if ($hasOutput) {
                    $lines[] = '';
                }

                foreach ($entry->lines as $line) {
                    $lines[] = $line;
                }

                $currentParent = null;
                $hasOutput = true;
            }

            if ($lines !== []) {
                $this->output->writeln($lines);
            }
        }

        return $exitCode;
    }

    /**
     * {@inheritdoc}
     */
    public function terminate(): void
    {
        $this->store()->flush();
    }

    /**
     * @param  array<int, string>  $lines
     * @return array{0:string,1:array<int, string>}|null
     */
    private function splitGroupedLines(array $lines): ?array
    {
        if (count($lines) < 2) {
            return null;
        }

        $parent = trim($lines[0]);
        $firstChild = $lines[1] ?? '';

        if ($parent === '' || ! str_starts_with($firstChild, E2EOutputFormatter::BRANCH_PREFIX)) {
            return null;
        }

        return [$parent, array_slice($lines, 1)];
    }
}
