<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract;
use ValcuAndrei\PestE2E\DTO\AuthPayloadDTO;
use ValcuAndrei\PestE2E\Enums\AuthModeType;

/**
 * @internal
 */
final class DefaultE2EAuthAction implements E2EAuthActionContract
{
    /**
     * Handle the auth payload.
     *
     * @throws InvalidArgumentException
     */
    public function handle(AuthPayloadDTO $payload): JsonResponse
    {
        $user = $payload->user;
        if (! $user instanceof Authenticatable) {
            return response()->json([
                'message' => 'User not found for ticket.',
            ], 401);
        }

        return match ($payload->mode) {
            AuthModeType::SESSION => $this->loginWithSession($payload->guard, $user),
            AuthModeType::SANCTUM => $this->issueSanctumToken($user),
        };
    }

    /**
     * Login the user using session authentication.
     */
    private function loginWithSession(string $guard, Authenticatable $user): JsonResponse
    {
        $guardInstance = Auth::guard($guard);
        assert($guardInstance instanceof StatefulGuard);

        $guardInstance->login($user);

        return response()->json([]);
    }

    /**
     * Issue a Sanctum token for the user.
     */
    private function issueSanctumToken(Authenticatable $user): JsonResponse
    {
        assert(method_exists($user, 'createToken'));
        $tokenResult = $user->createToken('pest-e2e');

        if (! is_object($tokenResult) || ! property_exists($tokenResult, 'plainTextToken')) {
            return response()->json([
                'message' => 'Unable to issue Sanctum token.',
            ], 501);
        }

        $token = $tokenResult->plainTextToken;
        if (! is_string($token) || $token === '') {
            return response()->json([
                'message' => 'Unable to issue Sanctum token.',
            ], 501);
        }

        return response()->json([
            'token' => $token,
        ]);
    }
}
