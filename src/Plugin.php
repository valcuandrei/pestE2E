<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E;

use Pest\Contracts\Plugins\AddsOutput;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Contracts\Plugins\Terminable;
use Symfony\Component\Console\Output\OutputInterface;
use ValcuAndrei\PestE2E\Collision\Events;
use ValcuAndrei\PestE2E\DTO\E2EOutputEntryDTO;
use ValcuAndrei\PestE2E\Runners\ServerRunner;
use ValcuAndrei\PestE2E\Support\CliOptions;
use ValcuAndrei\PestE2E\Support\E2EOutputFormatter;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

/**
 * @internal
 */
final class Plugin implements AddsOutput, HandlesArguments, Terminable
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

    private function resolveStore(): ?E2EOutputStore
    {
        try {
            return $this->store();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handleArguments(array $arguments): array
    {
        CliOptions::fromArguments($arguments);

        return CliOptions::filterArguments($arguments);
    }

    /**
     * {@inheritdoc}
     */
    public function addOutput(int $exitCode): int
    {
        $store = $this->resolveStore();

        if (! $store instanceof E2EOutputStore) {
            return $exitCode;
        }

        // Print per-test entries inline (without repeating parent test line)
        $perTestEntries = $store->getAllPerTestEntries();

        if ($perTestEntries !== []) {
            $lines = [];

            foreach ($perTestEntries as $entries) {
                foreach ($entries as $entry) {
                    if ($this->shouldSuppress($entry)) {
                        continue;
                    }

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
        $entries = $this->filterEntries($store->flush());

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
        $this->resolveStore()?->flush();
        ServerRunner::stopAll();
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

    private function shouldSuppress(E2EOutputEntryDTO $entry): bool
    {
        return $entry->ok && CliOptions::suppressPassedOutput();
    }

    /**
     * @param  array<int, E2EOutputEntryDTO>  $entries
     * @return array<int, E2EOutputEntryDTO>
     */
    private function filterEntries(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            fn (E2EOutputEntryDTO $entry): bool => ! $this->shouldSuppress($entry),
        ));
    }
}
