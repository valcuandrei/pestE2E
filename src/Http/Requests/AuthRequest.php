<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Http\Requests;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use ValcuAndrei\PestE2E\Contracts\AuthTicketStoreContract;
use ValcuAndrei\PestE2E\DTO\AuthPayloadDTO;
use ValcuAndrei\PestE2E\DTO\AuthTicketDTO;
use ValcuAndrei\PestE2E\Enums\AuthModeType;

/**
 * @internal
 */
final class AuthRequest extends FormRequest
{
    private ?AuthTicketDTO $ticket = null;

    private string $authorizationMessage = 'The ticket is invalid or expired.';

    private int $authorizationStatus = 401;

    public function __construct(
        private readonly AuthTicketStoreContract $ticketStore,
    ) {
        parent::__construct();
    }

    /**
     * Check if the ticket is valid and not expired.
     */
    public function authorize(): bool
    {
        $headerName = config('pest-e2e.auth.header.name', 'X-Pest-E2E');
        if (! is_string($headerName) || $headerName === '') {
            $headerName = 'X-Pest-E2E';
        }

        $headerValue = config('pest-e2e.auth.header.value', '1');
        if (! is_string($headerValue) || $headerValue === '') {
            $headerValue = '1';
        }

        if (! app()->environment('testing') || $this->header($headerName) !== $headerValue) {
            $this->authorizationMessage = 'E2E auth endpoint is only available for testing.';
            $this->authorizationStatus = 403;

            return false;
        }

        $ticket = $this->getTicket();

        if (! $ticket instanceof AuthTicketDTO) {
            $this->authorizationMessage = 'The ticket is invalid or expired.';
            $this->authorizationStatus = 401;

            return false;
        }

        return now()->lessThanOrEqualTo(Carbon::createFromTimestamp($ticket->ttlSeconds));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $guards = config('auth.guards', []);
        if (! is_array($guards)) {
            $guards = [];
        }

        return [
            'ticket' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->getTicket() instanceof AuthTicketDTO) {
                        $fail('The '.$attribute.' is invalid or expired.');
                    }
                },
            ],
            'mode' => ['nullable', Rule::enum(AuthModeType::class)],
            'guard' => ['nullable', Rule::in(array_keys($guards))],
        ];
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => $this->authorizationMessage,
            ], $this->authorizationStatus)
        );
    }

    /**
     * Get the ticket from the request.
     */
    public function getTicket(): ?AuthTicketDTO
    {
        if (! $this->ticket instanceof AuthTicketDTO) {
            $rawTicket = $this->input('ticket', '');
            $ticket = is_string($rawTicket) ? $rawTicket : '';
            $this->ticket = $this->ticketStore->consume($ticket);
        }

        return $this->ticket;
    }

    /**
     * Resolve the user from the ticket.
     */
    public function resolveUser(string $guard): ?Authenticatable
    {
        $ticket = $this->getTicket();
        if (! $ticket instanceof AuthTicketDTO) {
            return null;
        }

        /** @var object $guardInstance */
        $guardInstance = Auth::guard($guard);

        if (! method_exists($guardInstance, 'getProvider')) {
            return null;
        }

        $provider = $guardInstance->getProvider();

        if (! is_object($provider) || ! method_exists($provider, 'retrieveById')) {
            return null;
        }

        $user = $provider->retrieveById($ticket->userId);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Get the validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.enum' => 'The mode must be a valid auth mode.',
            'guard.in' => 'The guard must be a valid auth guard.',
        ];
    }

    /**
     * Get the validation error attributes.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'mode' => 'auth mode',
            'guard' => 'auth guard',
        ];
    }

    /**
     * Get the payload DTO.
     */
    public function toPayloadDTO(): AuthPayloadDTO
    {
        $ticket = $this->getTicket();
        assert($ticket instanceof AuthTicketDTO);

        $guard = $this->input('guard', 'web');
        if (! is_string($guard) || $guard === '') {
            $guard = 'web';
        }

        return new AuthPayloadDTO(
            ticket: $ticket,
            mode: $this->enum('mode', AuthModeType::class, AuthModeType::SESSION),
            guard: $guard,
        );
    }
}
