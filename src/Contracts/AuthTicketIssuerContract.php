<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Contracts;

/**
 * @internal
 */
interface AuthTicketIssuerContract
{
    /**
     * Issue a one-time login ticket for the given user.
     *
     * @param  array<string, mixed>  $meta
     * @return non-empty-string
     */
    public function issueForUser(mixed $user, array $meta = []): string;
}
