<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\AuthTicketStoreContract;

/**
 * @internal
 */
final class LaravelAuthTicketIssuer implements AuthTicketIssuerContract
{
    public function __construct(
        private readonly AuthTicketStoreContract $store,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function issueForUser(mixed $user, array $meta = []): string
    {
        if (! $user instanceof Authenticatable) {
            throw new InvalidArgumentException(
                'The provided user is not authenticatable. '.
                'Ensure you pass a Laravel Authenticatable model or rebind AuthTicketIssuerContract.'
            );
        }

        [$guard, $metaPayload] = $this->extractGuardAndMeta($meta);

        $ticket = Str::random(40);
        if ($ticket === '') {
            throw new InvalidArgumentException('Unable to generate a valid auth ticket.');
        }

        /** @var non-empty-string $ticket */
        $ttlConfig = config('pest-e2e.auth.ttl_seconds', 60);
        $ttlSeconds = is_int($ttlConfig)
            ? $ttlConfig
            : (is_numeric($ttlConfig) ? (int) $ttlConfig : 60);
        if ($ttlSeconds <= 0) {
            $ttlSeconds = 60;
        }

        $userId = $user->getAuthIdentifier();
        if (! is_int($userId) && ! is_string($userId)) {
            throw new InvalidArgumentException('User has an invalid auth identifier.');
        }

        $this->store->store(
            ticket: $ticket,
            userId: $userId,
            guard: $guard,
            meta: $metaPayload,
            ttlSeconds: now()->addSeconds($ttlSeconds)->unix()
        );

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{0:string,1:array<string, mixed>}
     */
    private function extractGuardAndMeta(array $meta): array
    {
        $guard = 'web';
        if (array_key_exists('guard', $meta)) {
            $guard = is_string($meta['guard']) ? $meta['guard'] : 'web';
        }

        if (array_key_exists('meta', $meta) && is_array($meta['meta'])) {
            return [$guard, $this->normalizeMeta($meta['meta'])];
        }

        $metaPayload = $meta;
        unset($metaPayload['guard'], $metaPayload['mode']);

        return [$guard, $this->normalizeMeta($metaPayload)];
    }

    /**
     * @param  array<mixed, mixed>  $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta(array $meta): array
    {
        $normalized = [];

        foreach ($meta as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
