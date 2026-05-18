<?php

declare(strict_types=1);

use ValcuAndrei\PestE2E\Enums\ServerRunnerType;
use ValcuAndrei\PestE2E\Runners\ServerRunner;

beforeEach(function () {
    ServerRunner::stopAll();
    unset($_ENV['IS_E2E_TEST'], $_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN']);
    putenv('TEST_TOKEN');
});

afterEach(function () {
    ServerRunner::stopAll();
    unset($_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN']);
    putenv('TEST_TOKEN');
});

it('skips starting server when laravel app is not servable', function (ServerRunnerType $type) {
    $runner = ServerRunner::instance($type);

    $called = false;

    $result = $runner->whenReady(function (string $baseUrl) use (&$called) {
        $called = true;

        expect($baseUrl)->toBeString();

        return 'ok';
    });

    expect($called)->toBeTrue()
        ->and($result)->toBe('ok');
})->with([
    'artisan' => [ServerRunnerType::ARTISAN],
    'php_builtin' => [ServerRunnerType::PHP_BUILTIN],
]);

it('rethrows exception from callback', function (ServerRunnerType $type) {
    $runner = ServerRunner::instance($type);

    expect(
        fn () => $runner->whenReady(
            fn () => throw new RuntimeException('boom')
        )
    )->toThrow(RuntimeException::class, 'boom');
})->with([
    'artisan' => [ServerRunnerType::ARTISAN],
    'php_builtin' => [ServerRunnerType::PHP_BUILTIN],
]);

it('returns the same runner instance for the same driver', function (ServerRunnerType $type) {
    $first = ServerRunner::instance($type);
    $second = ServerRunner::instance($type);

    expect($first)->toBe($second);
})->with([
    'artisan' => [ServerRunnerType::ARTISAN],
    'php_builtin' => [ServerRunnerType::PHP_BUILTIN],
]);

it('returns different runner instances for different drivers', function () {
    $artisan = ServerRunner::instance(ServerRunnerType::ARTISAN);
    $phpBuiltin = ServerRunner::instance(ServerRunnerType::PHP_BUILTIN);

    expect($artisan)->not->toBe($phpBuiltin);
});

it('baseUrl reflects assigned parallel worker port', function () {
    $runner = ServerRunner::instance(ServerRunnerType::PHP_BUILTIN);

    $portProperty = new ReflectionProperty($runner, 'port');
    $portProperty->setAccessible(true);
    $portProperty->setValue($runner, 8802);

    expect($runner->baseUrl())->toBe('http://127.0.0.1:8802')
        ->and($runner->port())->toBe(8802);
});

it('clears runner instances when stopAll is called', function (ServerRunnerType $type) {
    $first = ServerRunner::instance($type);
    ServerRunner::stopAll();
    $second = ServerRunner::instance($type);

    expect($second)->not->toBe($first);
})->with([
    'artisan' => [ServerRunnerType::ARTISAN],
    'php_builtin' => [ServerRunnerType::PHP_BUILTIN],
]);
