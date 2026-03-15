<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\DTO\JsonReportDTO;
use ValcuAndrei\PestE2E\DTO\ProcessPlanDTO;
use ValcuAndrei\PestE2E\DTO\ProcessResultDTO;
use ValcuAndrei\PestE2E\Support\NullAuthTicketIssuer;
use ValcuAndrei\PestE2E\Tests\Fakes\FakeUser;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedAuthTicketIssuer;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('passes auth ticket via params when acting as a user', function () {
    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator('run-123'));
    app()->instance(AuthTicketIssuerContract::class, new FixedAuthTicketIssuer('ticket-123'));
    app()->instance(JsWorkerContract::class, new class implements JsWorkerContract
    {
        public function run(ProcessPlanDTO $plan): ProcessResultDTO
        {
            expect($plan->params)->not->toBeNull();
            expect($plan->params?->params)->toMatchArray([
                'extra' => 'yes',
                'auth' => [
                    'ticket' => 'ticket-123',
                    'mode' => 'session',
                    'guard' => 'web',
                ],
            ]);

            return new ProcessResultDTO(
                exitCode: 0,
                stdout: JsonReportDTO::fakeWithPassedTest()
                    ->withTarget('frontend')
                    ->withRunId('run-123')
                    ->toJson(),
                stderr: '',
                durationSeconds: 0.01,
            );
        }
    });

    e2e()->target('frontend', fn ($p) => $p
        ->dir(getcwd())
    );

    e2e('frontend')
        ->actingAs((object) ['id' => 1])
        ->withParams(['extra' => 'yes'])
        ->run();
});

it('throws a friendly exception when actingAs is used without an auth ticket issuer', function () {
    app()->instance(AuthTicketIssuerContract::class, new NullAuthTicketIssuer);

    $user = new FakeUser(id: 1);

    expect(fn () => e2e('frontend')->actingAs($user))
        ->toThrow(
            RuntimeException::class,
            'No auth ticket issuer configured'
        );
});
