<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use RuntimeException;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;

/**
 * @internal
 */
final class NullAuthTicketIssuer implements AuthTicketIssuerContract
{
    /**
     * @param  array<string, mixed>  $meta
     * @return never
     */
    public function issueForUser(mixed $user, array $meta = []): string
    {
        throw new RuntimeException(
            'No auth ticket issuer configured. '.
                'You called actingAs()/loginAs() without configuring one. '.
                'Bind an AuthTicketIssuerContract implementation in the container.'
        );
    }
}
