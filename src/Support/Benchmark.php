<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

final class Benchmark
{
    /** @var array<string, array<array{label: string, time: float}>> */
    private static array $startTimes = [];

    /**
     * @return array{label: string, time: float}
     */
    public static function start(string $name = 'default'): array
    {
        $time = microtime(true);
        self::$startTimes[$name][] = [
            'label' => 'start',
            'time' => $time,
        ];

        return [
            'label' => 'Start '.$name.' at '.self::formatDuration($time),
            'time' => $time,
        ];
    }

    /**
     * @return array{label: string, time: float}
     */
    public static function mark(string $label, string $name = 'default'): array
    {
        $entries = self::entriesOrStart($name);
        $time = microtime(true);
        $last = $entries[array_key_last($entries)];
        $start = $entries[0]['time'];
        self::$startTimes[$name][] = [
            'label' => $label,
            'time' => $time,
        ];

        return [
            'label' => 'From '.$last['label'].' to '.$label.' took '.self::formatDuration($time - $last['time']).', total time: '.self::formatDuration($time - $start),
            'time' => $time - $start,
        ];
    }

    /**
     * @return array{label: string, time: float}
     */
    public static function end(string $name = 'default'): array
    {
        $entries = self::entriesOrStart($name);
        $time = microtime(true);
        $last = $entries[array_key_last($entries)];
        $start = $entries[0]['time'];

        return [
            'label' => 'From '.$last['label'].' to end took '.self::formatDuration($time - $last['time']).', total time: '.self::formatDuration($time - $start),
            'time' => $time - $start,
        ];
    }

    /**
     * Ensure a named timeline has at least one entry and return it as a
     * non-empty list. Guarantees `array_key_last() !== null` so callers can
     * index safely.
     *
     * @return non-empty-list<array{label: string, time: float}>
     */
    private static function entriesOrStart(string $name): array
    {
        if (! isset(self::$startTimes[$name]) || self::$startTimes[$name] === []) {
            self::start($name);
        }

        /** @var non-empty-list<array{label: string, time: float}> $entries */
        $entries = self::$startTimes[$name];

        return $entries;
    }

    private static function formatDuration(float $time): string
    {
        if ($time < 1) {
            return max(1, (int) round($time * 1000)).' ms';
        }

        return number_format($time, 2).' s';
    }
}
