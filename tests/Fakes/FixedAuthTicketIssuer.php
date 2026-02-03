<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Fakes;

use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;

final class FixedAuthTicketIssuer implements AuthTicketIssuerContract
{
    /**
     * Create a new FixedAuthTicketIssuer instance.
     */
    public function __construct(
        private readonly string $ticket = 'ticket-123',
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function issueForUser(mixed $user, array $meta = []): string
    {
        return $this->ticket;
    }
}
