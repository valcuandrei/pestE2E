<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\DTO;

use Illuminate\Contracts\Auth\Authenticatable;
use ValcuAndrei\PestE2E\Enums\AuthModeType;

final readonly class AuthPayloadDTO
{
    public function __construct(
        public AuthTicketDTO|string $ticket,
        public AuthModeType $mode = AuthModeType::SESSION,
        public string $guard = 'web',
        public ?Authenticatable $user = null,
    ) {}

    /**
     * Create a new AuthPayloadDTO instance with the given ticket.
     */
    public function withTicket(AuthTicketDTO|string $ticket): self
    {
        return new self(ticket: $ticket, mode: $this->mode, guard: $this->guard, user: $this->user);
    }

    /**
     * Create a new AuthPayloadDTO instance with the given mode.
     */
    public function withMode(AuthModeType $mode): self
    {
        return new self(ticket: $this->ticket, mode: $mode, guard: $this->guard, user: $this->user);
    }

    /**
     * Create a new AuthPayloadDTO instance with the given guard.
     */
    public function withGuard(string $guard): self
    {
        return new self(ticket: $this->ticket, mode: $this->mode, guard: $guard, user: $this->user);
    }

    /**
     * Create a new AuthPayloadDTO instance with the given user.
     */
    public function withUser(?Authenticatable $user): self
    {
        return new self(ticket: $this->ticket, mode: $this->mode, guard: $this->guard, user: $user);
    }

    /**
     * Get the payload as an array.
     *
     * @return array{
     *  ticket:array{
     *    user_id:int|string,
     *    guard:string,
     *    meta:array<string, mixed>,
     *  }|string,
     *  mode:'session' | 'sanctum',
     *  guard:string,
     * }
     */
    public function toArray(): array
    {
        return [
            'ticket' => $this->ticket instanceof AuthTicketDTO ? $this->ticket->toArray() : $this->ticket,
            'mode' => $this->mode->value,
            'guard' => $this->guard,
        ];
    }
}
