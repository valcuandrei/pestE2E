<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

use ValcuAndrei\PestE2E\DTO\AuthTicketDTO;

/**
 * @internal
 */
interface AuthTicketStoreContract
{
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
    ): void;

    /**
     * Consume a ticket once.
     */
    public function consume(string $ticket): ?AuthTicketDTO;
}
