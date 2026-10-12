<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\Support\AdmissionController;
use ValcuAndrei\PestE2E\Support\FakeResourceSampler;
use ValcuAndrei\PestE2E\Tests\Fakes\FakeUser;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;
use ValcuAndrei\PestE2E\Tests\Support\ArrayUserProvider;

/**
 * Auth-ticket lifetime under queued / parallel E2E execution.
 *
 * A run can be held back for minutes by the admission controller before its
 * JS runner is launched. These tests replay that wait on a virtual clock (the
 * controller's injected sleeper moves Laravel's clock forward instead of
 * sleeping) and let a fake JS worker play the part of Playwright's
 * globalSetup: it exchanges `params.auth.ticket` at the real auth endpoint.
 */
beforeEach(function (): void {
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
        'pest-e2e.auth.ttl_seconds' => 60,
    ]);

    Route::middleware('web')->get('/whoami', fn () => response()->json(['id' => auth()->id()]));

    app()->instance('pest-e2e.test-users', [
        1 => new FakeUser(1),
        2 => new FakeUser(2),
    ]);
    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator('run-queued-auth'));

    e2e()->target('frontend', fn ($p) => $p->dir(getcwd()));

    Carbon::setTestNow(Carbon::now());

    $this->lockDir = sys_get_temp_dir().'/pest-e2e-queued-auth-tests-'.uniqid();
    mkdir($this->lockDir, 0700, true);

    // The runner is resolved when a target handle is created, so the
    // controller and the worker are bound once and scripted per test.
    app()->instance('pest-e2e.test-sampler', $sampler = new FakeResourceSampler);
    app()->instance(AdmissionController::class, new AdmissionController(
        sampler: $sampler,
        cpuHighPct: 80.0,
        cpuLowPct: 75.0,
        memoryHighPct: 85.0,
        memoryLowPct: 80.0,
        cooldownMs: 0,
        backoffMs: 10_000,
        jitterMs: 0,
        maxWaitSeconds: 300,
        lockPath: $this->lockDir.'/admission.lock',
        sleeper: static function (float $seconds): void {
            Carbon::setTestNow(Carbon::now()->addMilliseconds((int) round($seconds * 1000)));
        },
        clock: static fn (): float => (float) Carbon::now()->format('U.u'),
    ));

    app()->instance(JsWorkerContract::class, new class implements JsWorkerContract
    {
        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            $auth = $plan->params?->params['auth'] ?? [];
            $ticket = is_array($auth) && is_string($auth['ticket'] ?? null) ? $auth['ticket'] : null;

            app('pest-e2e.test-browser')($ticket);

            return new ProcessResultDTO(
                exitCode: 0,
                stdout: JsonReportDTO::fakeWithPassedTest()
                    ->withTarget('frontend')
                    ->withRunId('run-queued-auth')
                    ->toJson(),
                stderr: '',
                durationSeconds: 0.01,
            );
        }
    });

    $this->exchangeTicket = fn (?string $ticket): TestResponse => $this->postJson(
        route('pest-e2e.auth.login'),
        ['ticket' => $ticket, 'mode' => 'session', 'guard' => 'web'],
        [
            (string) config('pest-e2e.auth.header.name') => (string) config('pest-e2e.auth.header.value'),
        ],
    );
});

afterEach(function (): void {
    @unlink($this->lockDir.'/admission.lock');
    @unlink($this->lockDir.'/admission.lock.state');
    @rmdir($this->lockDir);
});

/**
 * Script what the admission controller sees next: host memory above the
 * high-water mark for `$busyChecks` attempts, then (unless the host never
 * recovers) back under it. Every failed attempt backs off for 10 virtual
 * seconds.
 */
function queueBehindBusyHost(int $busyChecks, bool $recovers = true): void
{
    /** @var FakeResourceSampler $sampler */
    $sampler = app('pest-e2e.test-sampler');

    for ($i = 0; $i < $busyChecks; $i++) {
        $sampler->enqueue(10.0, 95.0);
    }

    if ($recovers) {
        $sampler->enqueue(10.0, 50.0);
    }
}

/**
 * Decide what the "browser" does when the JS runner is launched. It receives
 * the ticket found in `params.auth.ticket` at that moment.
 *
 * @param  Closure(?string): void  $browser
 */
function launchBrowserWith(Closure $browser): void
{
    app()->instance('pest-e2e.test-browser', $browser);
}

it('authenticates a run that waited in the admission queue for longer than the ticket lifetime', function (): void {
    queueBehindBusyHost(busyChecks: 9); // 9 × 10s = 90s queued, ticket lifetime is 60s

    $queuedAt = Carbon::now();
    $waitedSeconds = null;
    $response = null;

    launchBrowserWith(function (?string $ticket) use ($queuedAt, &$waitedSeconds, &$response): void {
        $waitedSeconds = $queuedAt->diffInSeconds(Carbon::now());
        $response = ($this->exchangeTicket)($ticket);
    });

    // A credential minted when the test asked for auth — what actingAs()
    // used to hand to the JS runner — is dead by the time the run is admitted.
    $mintedBeforeQueueing = app(AuthTicketIssuerContract::class)->issueForUser(new FakeUser(1), ['guard' => 'web']);

    e2e('frontend')->actingAs(new FakeUser(1))->run();

    expect($waitedSeconds)->toBeGreaterThan(60);
    $response->assertOk();
    $this->getJson('/whoami')->assertOk()->assertJson(['id' => 1]);

    ($this->exchangeTicket)($mintedBeforeQueueing)->assertUnauthorized();
});

it('authenticates a run that is let through only because the admission max-wait ceiling elapsed', function (): void {
    // The host never recovers, so the run waits the full ceiling — which is
    // at least as long as the ticket lifetime.
    config(['pest-e2e.auth.ttl_seconds' => 300]);
    queueBehindBusyHost(busyChecks: 1, recovers: false);

    $queuedAt = Carbon::now();
    $waitedSeconds = null;
    $response = null;

    launchBrowserWith(function (?string $ticket) use ($queuedAt, &$waitedSeconds, &$response): void {
        $waitedSeconds = $queuedAt->diffInSeconds(Carbon::now());
        $response = ($this->exchangeTicket)($ticket);
    });

    e2e('frontend')->actingAs(new FakeUser(1))->run();

    expect($waitedSeconds)->toBeGreaterThanOrEqual(300);
    $response->assertOk();
    $this->getJson('/whoami')->assertOk()->assertJson(['id' => 1]);
});

it('authenticates a run that waited behind another worker\'s long-running run', function (): void {
    // Both tests have already asked for auth before either JS runner starts.
    $first = e2e('frontend')->actingAs(new FakeUser(1));
    $second = e2e('frontend')->actingAs(new FakeUser(2));

    $responses = [];

    // First run: admitted at once, and its browser work outlasts a ticket lifetime.
    queueBehindBusyHost(busyChecks: 0);
    launchBrowserWith(function (?string $ticket) use (&$responses): void {
        $responses['first'] = ($this->exchangeTicket)($ticket);
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
    });
    $first->run();

    // Second run: the first one is still loading the host, so it queues again.
    queueBehindBusyHost(busyChecks: 6);
    launchBrowserWith(function (?string $ticket) use (&$responses): void {
        $responses['second'] = ($this->exchangeTicket)($ticket);
    });
    $second->run();

    $responses['first']->assertOk();
    $responses['second']->assertOk();
    $this->getJson('/whoami')->assertOk()->assertJson(['id' => 2]);
});

it('still expires a ticket that is not exchanged within its lifetime after launch', function (): void {
    queueBehindBusyHost(busyChecks: 0);

    $response = null;

    launchBrowserWith(function (?string $ticket) use (&$response): void {
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $response = ($this->exchangeTicket)($ticket);
    });

    e2e('frontend')->actingAs(new FakeUser(1))->run();

    $response->assertUnauthorized()->assertJson(['message' => 'The ticket is invalid or expired.']);
    $this->getJson('/whoami')->assertJson(['id' => null]);
});

it('issues a fresh single-use ticket for every run and rejects a replay', function (): void {
    queueBehindBusyHost(busyChecks: 0);

    $tickets = [];
    $responses = [];

    launchBrowserWith(function (?string $ticket) use (&$tickets, &$responses): void {
        $tickets[] = $ticket;
        $responses[] = ($this->exchangeTicket)($ticket);
    });

    $handle = e2e('frontend')->actingAs(new FakeUser(1));
    $handle->run();
    $handle->run();

    expect($tickets)->toHaveCount(2)
        ->and($tickets[0])->toBeString()->not->toBe($tickets[1]);
    $responses[0]->assertOk();
    $responses[1]->assertOk();

    ($this->exchangeTicket)($tickets[0])->assertUnauthorized();
    ($this->exchangeTicket)($tickets[1])->assertUnauthorized();
});

it('revokes a ticket the js runner never exchanged once the run is over', function (): void {
    queueBehindBusyHost(busyChecks: 0);

    $unused = null;

    launchBrowserWith(function (?string $ticket) use (&$unused): void {
        $unused = $ticket; // the browser crashed before globalSetup authenticated
    });

    e2e('frontend')->actingAs(new FakeUser(1))->run();

    // Still inside its lifetime, yet no longer exchangeable.
    expect($unused)->toBeString();
    ($this->exchangeTicket)($unused)->assertUnauthorized();
});

it('does not let another parallel worker exchange a ticket', function (): void {
    queueBehindBusyHost(busyChecks: 0);

    $originalToken = $_SERVER['TEST_TOKEN'] ?? null;
    $responses = [];

    launchBrowserWith(function (?string $ticket) use (&$responses): void {
        $_SERVER['TEST_TOKEN'] = '8';
        $responses['other worker'] = ($this->exchangeTicket)($ticket);

        $_SERVER['TEST_TOKEN'] = '7';
        $responses['own worker'] = ($this->exchangeTicket)($ticket);
    });

    try {
        $_SERVER['TEST_TOKEN'] = '7';

        e2e('frontend')->actingAs(new FakeUser(1))->run();
    } finally {
        if ($originalToken === null) {
            unset($_SERVER['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $originalToken;
        }
    }

    $responses['other worker']->assertUnauthorized();
    $responses['own worker']->assertOk();
});
