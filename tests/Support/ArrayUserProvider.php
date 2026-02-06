<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * @internal
 */
final class ArrayUserProvider implements UserProvider
{
    /** @param array<int, Authenticatable> $users */
    public function __construct(private array $users) {}

    /**
     * Retrieve a user by their primary key.
     *
     * @param  int|string  $identifier
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->users[(int) $identifier] ?? null;
    }

    /**
     * Retrieve a user by their remember token.
     *
     * @param  int|string  $identifier
     */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return $this->users[(int) $identifier] ?? null;
    }

    /**
     * Update the "remember me" token for the given user.
     */
    public function updateRememberToken(Authenticatable $user, mixed $token): void {}

    /**
     * Retrieve a user by their credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    /**
     * Validate a user's credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return true;
    }

    /**
     * Rehash a user's password if required.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}
