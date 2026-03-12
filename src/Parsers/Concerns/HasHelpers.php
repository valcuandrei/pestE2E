<?php

namespace ValcuAndrei\PestE2E\Parsers\Concerns;

use ValcuAndrei\PestE2E\Exceptions\JsonReportParserException;

trait HasHelpers
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function requireString(array $data, string $key, string $source): string
    {
        if (! array_key_exists($key, $data) || ! is_string($data[$key]) || $data[$key] === '') {
            throw new JsonReportParserException("Missing/invalid string field ({$source}): {$key}");
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function requireArray(array $data, string $key, string $source): array
    {
        if (! array_key_exists($key, $data) || ! is_array($data[$key])) {
            throw new JsonReportParserException("Missing/invalid object field ({$source}): {$key}");
        }

        /** @var array<string, mixed> $arr */
        $arr = $data[$key];

        return $arr;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requireInt(array $data, string $key, string $source): int
    {
        if (! array_key_exists($key, $data) || ! is_int($data[$key])) {
            throw new JsonReportParserException("Missing/invalid int field ({$source}): {$key}");
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optionalString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optionalInt(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return is_int($data[$key]) ? $data[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    private function optionalStringArray(array $data, string $key): ?array
    {
        if (! isset($data[$key]) || ! is_array($data[$key])) {
            return null;
        }

        /** @var list<string> $result */
        $result = [];
        foreach ($data[$key] as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
