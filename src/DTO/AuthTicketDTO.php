<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

final readonly class AuthTicketDTO
{
    /**
     * @param  int|string  $userId  User identifier.
     * @param  string  $guard  Must be a valid auth guard name.
     * @param  array<string, mixed>  $meta  Optional metadata.
     */
    public function __construct(
        public int|string $userId,
        public string $guard,
        public int $ttlSeconds,
        public array $meta = [],
    ) {}

    /**
     * Get the ticket as an array.
     *
     * @return array{
     *  user_id:int|string,
     *  guard:string,
     *  ttl_seconds:int,
     *  meta:array<string, mixed>,
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'guard' => $this->guard,
            'ttl_seconds' => $this->ttlSeconds,
            'meta' => $this->meta,
        ];
    }

    /**
     * Create a new AuthTicketDTO instance from an array.
     *
     * @param  array{
     *  user_id:int|string,
     *  guard:string,
     *  ttl_seconds:int,
     *  meta:array<string, mixed>,
     * }  $array
     */
    public static function fromArray(array $array): ?self
    {
        if (empty($array['user_id']) || empty($array['guard'])) {
            return null;
        }

        return new self(
            userId: $array['user_id'],
            guard: $array['guard'],
            ttlSeconds: $array['ttl_seconds'],
            meta: $array['meta'],
        );
    }
}
