<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
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
    }

    /**
     * Consume a single-use auth ticket.
     */
    public function consume(string $ticket): ?AuthTicketDTO
    {
        $payload = $this->cache->pull($this->key($ticket));
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
        return 'pest-e2e:auth-ticket:'.$ticket;
    }
}
