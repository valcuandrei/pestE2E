<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use RuntimeException;
use ValcuAndrei\PestE2E\Contracts\AuthTicketStoreContract;
use ValcuAndrei\PestE2E\DTO\AuthTicketDTO;

/**
 * @internal
 */
final class CacheAuthTicketStore implements AuthTicketStoreContract
{
    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Store a single-use auth ticket.
     *
     * @param  array<string, mixed>  $meta
     */
    public function store(
        string $ticket,
        int|string $userId,
        string $guard,
        array $meta,
        int $ttlSeconds,
    ): void {
        $this->ensureFileCacheDirectoryExists();

        $payload = new AuthTicketDTO(
            userId: $userId,
            guard: $guard,
            ttlSeconds: $ttlSeconds,
            meta: $meta,
        );

        // $ttlSeconds is actually a unix timestamp of expiration
        // Convert to seconds from now for cache TTL
        $cacheTtl = max(1, $ttlSeconds - time());
        $this->cache->put($this->key($ticket), $payload->toArray(), $cacheTtl);
        $this->storeFileTicket($ticket, $payload);
    }

    /**
     * Consume a single-use auth ticket.
     */
    public function consume(string $ticket): ?AuthTicketDTO
    {
        $payload = $this->cache->pull($this->key($ticket));

        if (is_array($payload)) {
            $this->removeFileTicket($ticket);
        } else {
            $payload = $this->pullFileTicket($ticket);
        }

        if (! is_array($payload)) {
            return null;
        }

        if (
            ! array_key_exists('user_id', $payload)
            || ! is_int($payload['user_id']) && ! is_string($payload['user_id'])
            || ! array_key_exists('guard', $payload)
            || ! is_string($payload['guard'])
            || ! array_key_exists('ttl_seconds', $payload)
            || ! is_int($payload['ttl_seconds'])
            || ! array_key_exists('meta', $payload)
            || ! is_array($payload['meta'])
        ) {
            return null;
        }

        /** @var array{user_id:int|string, guard:string, ttl_seconds:int, meta:array<string, mixed>} $payload */
        return AuthTicketDTO::fromArray($payload);
    }

    /**
     * Get the cache key for a single-use auth ticket.
     */
    private function key(string $ticket): string
    {
        $prefix = ParallelWorkerContext::token();

        if ($prefix === null) {
            return 'pest-e2e:auth-ticket:'.$ticket;
        }

        return "pest-e2e:worker-{$prefix}:auth-ticket:{$ticket}";
    }

    private function ensureFileCacheDirectoryExists(): void
    {
        if (config('cache.default') !== 'file') {
            return;
        }

        $path = config('cache.stores.file.path');

        if (! is_string($path) || $path === '' || is_dir($path)) {
            return;
        }

        @mkdir($path, 0775, true);
    }

    private function storeFileTicket(string $ticket, AuthTicketDTO $payload): void
    {
        $dir = $this->ticketDirectory();

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create Pest E2E auth ticket directory: {$dir}");
        }

        $json = json_encode($payload->toArray(), JSON_THROW_ON_ERROR);
        $path = $this->ticketPath($ticket);

        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write Pest E2E auth ticket: {$path}");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pullFileTicket(string $ticket): ?array
    {
        $path = $this->ticketPath($ticket);

        if (! is_file($path)) {
            return null;
        }

        $json = @file_get_contents($path);
        @unlink($path);

        if (! is_string($json) || $json === '') {
            return null;
        }

        /** @var mixed $payload */
        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function removeFileTicket(string $ticket): void
    {
        $path = $this->ticketPath($ticket);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function ticketDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/pest-e2e/auth-tickets'.ParallelWorkerContext::pathSuffix();
    }

    private function ticketPath(string $ticket): string
    {
        return $this->ticketDirectory().'/'.hash('sha256', $ticket).'.json';
    }
}
