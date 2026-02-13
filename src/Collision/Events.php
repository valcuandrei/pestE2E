<?php

declare(strict_types=1);

namespace Pest\Collision;

use NunoMaduro\Collision\Adapters\Phpunit\TestResult;
use Symfony\Component\Console\Output\OutputInterface;
use ValcuAndrei\PestE2E\Support\E2EOutputStore;

/**
 * Hooks used by Collision's test line renderer.
 *
 * This class is discovered by Collision using class_exists() checks and allows
 * us to append E2E lines immediately after each Pest test line.
 *
 * @internal
 */
final class Events
{
    private static ?OutputInterface $output = null;

    public static function setOutput(OutputInterface $output): void
    {
        self::$output = $output;
    }

    public static function beforeTestMethodDescription(TestResult $result, string $description): string
    {
        return $description;
    }

    public static function afterTestMethodDescription(TestResult $result): void
    {
        if (! function_exists('app')) {
            return;
        }

        /** @var mixed $resolved */
        $resolved = app(E2EOutputStore::class);

        if (! $resolved instanceof E2EOutputStore) {
            return;
        }

        $entries = $resolved->getForTest($result->id);

        if ($entries === []) {
            return;
        }

        foreach ($entries as $entry) {
            $lines = $entry->lines;
            $counter = count($lines);

            if ($counter < 2) {
                continue;
            }

            // Skip parent line (already printed by Pest), keep nested E2E block.
            for ($i = 1; $i < $counter; $i++) {
                self::writeLine($lines[$i]);
            }

            self::writeLine('');
        }

        $resolved->removeForTest($result->id);
    }

    private static function writeLine(string $line): void
    {
        if (self::$output instanceof OutputInterface) {
            self::$output->writeln($line);

            return;
        }

        fwrite(STDOUT, $line."\n");
    }
}
