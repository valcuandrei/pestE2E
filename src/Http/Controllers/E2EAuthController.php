<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;
use ValcuAndrei\PestE2E\Contracts\E2EAuthActionContract;
use ValcuAndrei\PestE2E\Enums\AuthModeType;
use ValcuAndrei\PestE2E\Http\Requests\AuthRequest;

/**
 * @internal
 */
final class E2EAuthController
{
    /**
     * Handle the E2E auth request.
     */
    public function __invoke(
        AuthRequest $request,
        E2EAuthActionContract $action
    ): JsonResponse {
        $payload = $request->toPayloadDTO();
        $user = $request->resolveUser($payload->guard);

        if (! $user instanceof Authenticatable) {
            return response()->json([
                'message' => 'User not found for ticket.',
            ], 401);
        }

        if ($payload->mode === AuthModeType::SESSION) {
            $guardInstance = Auth::guard($payload->guard);

            if (! $guardInstance instanceof StatefulGuard) {
                return response()->json([
                    'message' => 'Guard does not support session authentication.',
                ], 501);
            }
        }

        if ($payload->mode === AuthModeType::SANCTUM) {
            if (! class_exists(HasApiTokens::class)) {
                return response()->json([
                    'message' => 'Sanctum is not installed. Install laravel/sanctum to use token mode.',
                ], 501);
            }

            $uses = class_uses_recursive($user);

            if (! in_array(HasApiTokens::class, $uses, true)) {
                return response()->json([
                    'message' => 'User model does not use Laravel\\Sanctum\\HasApiTokens.',
                ], 501);
            }

            if (! method_exists($user, 'createToken')) {
                return response()->json([
                    'message' => 'User model cannot issue Sanctum tokens.',
                ], 501);
            }
        }

        return $action->handle($payload->withUser($user));
    }
}
