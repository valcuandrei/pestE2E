<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Runners\ServerRunner;

it('skips starting server when laravel app is not servable', function () {
    $runner = ServerRunner::instance();

    $called = false;

    $result = $runner->whenReady(function (string $baseUrl) use (&$called) {
        $called = true;

        expect($baseUrl)->toBeString();

        return 'ok';
    });

    expect($called)->toBeTrue()
        ->and($result)->toBe('ok');
});

it('rethrows exception even when keepAliveOnFailure is true', function () {
    $runner = ServerRunner::instance();

    expect(
        fn () => $runner->whenReady(
            fn () => throw new \RuntimeException('boom')
        )
    )->toThrow(\RuntimeException::class, 'boom');
});
