<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Runners\ServerRunner;

beforeEach(function () {
    ServerRunner::stopAll();
});

afterEach(function () {
    ServerRunner::stopAll();
});

it('skips starting server when laravel app is not servable', function () {
    $runner = new ServerRunner;

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
    $runner = new ServerRunner;

    expect(
        fn () => $runner->run(
            fn () => throw new \RuntimeException('boom'),
            keepAliveOnFailure: true
        )
    )->toThrow(\RuntimeException::class, 'boom');
});

it('returns the same shared runner instance for the same driver', function () {
    $first = ServerRunner::getOrCreate();
    $second = ServerRunner::getOrCreate();

    expect($first)->toBe($second);
});

it('clears shared runners when stopAll is called', function () {
    $first = ServerRunner::getOrCreate();
    ServerRunner::stopAll();
    $second = ServerRunner::getOrCreate();

    expect($second)->not->toBe($first);
});
