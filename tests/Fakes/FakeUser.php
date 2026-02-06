<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Tests\Fakes;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @internal
 */
final class FakeUser implements Authenticatable
{
    /**
     * Create a new FakeUser instance.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = 'Pest',
    ) {}

    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * Get the name of the password for the user.
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    /**
     * Get the remember token for the user.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * Set the remember token for the user.
     *
     * @param  string  $value
     */
    public function setRememberToken($value): void {}

    /**
     * Get the name of the remember token for the user.
     */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
