<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Runners\ServerRunner;

it('skips starting server when laravel app is not servable', function () {
    $runner = new ServerRunner();

    $called = false;

    $result = $runner->run(function (string $baseUrl) use (&$called) {
        $called = true;

        expect($baseUrl)->toBeString();

        return 'ok';
    });

    expect($called)->toBeTrue()
        ->and($result)->toBe('ok');
});

it('rethrows exception even when keepAliveOnFailure is true', function () {
    $runner = new ServerRunner();

    expect(
        fn() =>
        $runner->run(
            fn() => throw new \RuntimeException('boom'),
            keepAliveOnFailure: true
        )
    )->toThrow(\RuntimeException::class, 'boom');
});
