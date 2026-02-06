<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Contracts\AuthTicketIssuerContract;
use ValcuAndrei\PestE2E\Contracts\RunIdGeneratorContract;
use ValcuAndrei\PestE2E\Support\NullAuthTicketIssuer;
use ValcuAndrei\PestE2E\Tests\Fakes\FakeUser;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedAuthTicketIssuer;
use ValcuAndrei\PestE2E\Tests\Fakes\FixedRunIdGenerator;

it('passes auth ticket via params when acting as a user', function () {
    $nodePath = trim((string) shell_exec('command -v node'));

    if ($nodePath === '') {
        $this->markTestSkipped('Node.js not available on PATH.');
    }

    app()->instance(RunIdGeneratorContract::class, new FixedRunIdGenerator('run-123'));
    app()->instance(AuthTicketIssuerContract::class, new FixedAuthTicketIssuer('ticket-123'));

    $moduleFile = tempnam(sys_get_temp_dir(), 'pest-e2e-auth-');
    $reportPath = tempnam(sys_get_temp_dir(), 'pest-e2e-report-');

    expect($moduleFile)->not->toBeFalse()
        ->and($reportPath)->not->toBeFalse();

    $modulePath = $moduleFile.'.mjs';
    rename($moduleFile, $modulePath);

    $moduleContents = <<<'JS'
export async function check({ params }) {
  if (!params.auth || params.auth.ticket !== 'ticket-123') throw new Error('missing ticket');
  if (params.auth.mode !== 'session') throw new Error('missing mode');
  if (params.extra !== 'yes') throw new Error('missing extra');
}
JS;

    file_put_contents($modulePath, $moduleContents);

    try {
        e2e()->target('frontend', fn ($p) => $p
            ->dir(dirname($modulePath))
            ->runner('node')
            ->command('php -r "exit(0);"')
            ->report('json', $reportPath)
        );

        e2e('frontend')
            ->actingAs((object) ['id' => 1])
            ->withParams(['extra' => 'yes'])
            ->call($modulePath, 'check');
    } finally {
        @unlink($modulePath);
        @unlink($reportPath);
    }
});

it('throws a friendly exception when actingAs is used without an auth ticket issuer', function () {
    app()->instance(AuthTicketIssuerContract::class, new NullAuthTicketIssuer);

    $user = new FakeUser(id: 1);

    expect(fn () => e2e('frontend')->actingAs($user))
        ->toThrow(
            \RuntimeException::class,
            'No auth ticket issuer configured'
        );
});
