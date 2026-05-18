<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

/**
 * @internal
 *
 * @deprecated Use {@see ParallelWorkerContext} instead.
 */
final class ParallelWorker
{
    public static function token(): ?string
    {
        return ParallelWorkerContext::token();
    }

    public static function isParallel(): bool
    {
        return ParallelWorkerContext::isParallel();
    }

    public static function serverPort(?int $basePort = null): ?int
    {
        return ParallelWorkerContext::serverPort($basePort);
    }

    public static function cachePrefix(?string $existing = null): ?string
    {
        return ParallelWorkerContext::cachePrefix($existing);
    }

    public static function testDatabaseName(string $database): string
    {
        return ParallelWorkerContext::testDatabaseName($database);
    }

    /**
     * @return array<string, string>
     */
    public static function serverEnvironment(): array
    {
        return ParallelWorkerContext::serverEnvironment();
    }

    public static function pathSuffix(): string
    {
        return ParallelWorkerContext::pathSuffix();
    }
}
