<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Tests\Fakes\FakeUser;
use ValcuAndrei\PestE2E\Tests\Support\ArrayUserProvider;

beforeEach(function () {
    Auth::provider('pest_e2e_array', function ($app, array $config) {
        /** @var array<int, Authenticatable> $users */
        $users = $app->bound('pest-e2e.test-users')
            ? $app->make('pest-e2e.test-users')
            : [];

        return new ArrayUserProvider($users);
    });

    config([
        'auth.defaults.guard' => 'web',
        'auth.guards.web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'auth.providers.users' => [
            'driver' => 'pest_e2e_array',
        ],
    ]);

    // Whoami route to verify session auth actually stuck
    Route::middleware('web')->get('/whoami', function () {
        return response()->json(['id' => auth()->id()]);
    });
});

/**
 * Get the pest-e2e auth header.
 *
 * @return array<string, string>
 */
function pestE2eAuthHeader(): array
{
    $headerName = (string) config('pest-e2e.auth.header.name', 'X-Pest-E2E');
    $headerValue = (string) config('pest-e2e.auth.header.value', '1');

    return [$headerName => $headerValue];
}

it('logs in via the testing auth endpoint (session mode)', function () {
    $user = new FakeUser(1);

    app()->instance('pest-e2e.test-users', [
        1 => $user,
    ]);

    $issuer = app(AuthTicketIssuerContract::class);
    $ticket = $issuer->issueForUser($user, ['guard' => 'web']);

    $response = $this->postJson(
        route('pest-e2e.auth.login'),
        ['ticket' => $ticket, 'mode' => 'session'],
        pestE2eAuthHeader(),
    );

    $response->assertStatus(200);

    $whoami = $this->getJson('/whoami');
    $whoami->assertStatus(200)->assertJson(['id' => 1]);
});

it('rejects reused tickets', function () {
    $user = new FakeUser(1);
    app()->instance('pest-e2e.test-users', [1 => $user]);

    $issuer = app(AuthTicketIssuerContract::class);
    $ticket = $issuer->issueForUser($user, ['guard' => 'web']);

    $first = $this->postJson(
        route('pest-e2e.auth.login'),
        ['ticket' => $ticket],
        pestE2eAuthHeader(),
    );

    $first->assertOk();

    $second = $this->postJson(
        route('pest-e2e.auth.login'),
        ['ticket' => $ticket],
        pestE2eAuthHeader(),
    );

    $second->assertUnauthorized()->assertJsonStructure(['message']);
});

it('honors ticket TTL', function () {
    config(['pest-e2e.auth.ttl_seconds' => 1]);

    $user = new FakeUser(1);
    app()->instance('pest-e2e.test-users', [1 => $user]);

    $issuer = app(AuthTicketIssuerContract::class);
    $ticket = $issuer->issueForUser($user, ['guard' => 'web']);

    usleep(1_500_000); // 1.5s

    $response = $this->postJson(
        route('pest-e2e.auth.login'),
        ['ticket' => $ticket],
        pestE2eAuthHeader(),
    );

    $response->assertUnauthorized()->assertJsonStructure(['message']);
});

it('handles sanctum mode availability', function () {
    $user = new FakeUser(1);
    app()->instance('pest-e2e.test-users', [1 => $user]);

    $issuer = app(AuthTicketIssuerContract::class);
    $ticket = $issuer->issueForUser($user, ['guard' => 'web']);

    $response = $this->postJson(
        route('pest-e2e.auth.login'),
        ['ticket' => $ticket, 'mode' => 'sanctum'],
        pestE2eAuthHeader(),
    );

    if (class_exists(\Laravel\Sanctum\HasApiTokens::class)) {
        $response->assertOk()->assertJsonStructure(['token']);
    } else {
        $response->assertServerError()->assertJsonStructure(['message']);
    }
});
