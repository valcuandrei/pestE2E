<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E;

use Pest\Contracts\Plugins\AddsOutput;
use Pest\Contracts\Plugins\Terminable;
use Symfony\Component\Console\Output\OutputInterface;
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
        private readonly E2EOutputStore $store,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function addOutput(int $exitCode): int
    {
        $entries = $this->store->flush();
        $lines = [];

        foreach ($entries as $index => $entry) {
            if ($index > 0) {
                $lines[] = '';
            }

            foreach ($entry->lines as $line) {
                $lines[] = $line;
            }
        }

        if ($lines !== []) {
            $this->output->writeln($lines);
        }

        return $exitCode;
    }

    /**
     * {@inheritdoc}
     */
    public function terminate(): void
    {
        $this->store->flush();
    }
}
