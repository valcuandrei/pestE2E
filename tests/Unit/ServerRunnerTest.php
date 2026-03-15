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

it('rethrows exception from callback', function () {
    $runner = ServerRunner::instance();

    expect(
        fn () => $runner->whenReady(
            fn () => throw new RuntimeException('boom')
        )
    )->toThrow(RuntimeException::class, 'boom');
});

it('returns the same runner instance for the same driver', function () {
    $first = ServerRunner::instance();
    $second = ServerRunner::instance();

    expect($first)->toBe($second);
});

it('clears runner instances when stopAll is called', function () {
    $first = ServerRunner::instance();
    ServerRunner::stopAll();
    $second = ServerRunner::instance();

    expect($second)->not->toBe($first);
});
