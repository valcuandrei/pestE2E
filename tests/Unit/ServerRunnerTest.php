<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
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

it('defaults the managed-server ready timeout to 45 seconds', function () {
    config()->set('pest-e2e.server.ready_timeout_seconds', null);

    expect(ServerRunner::readyTimeoutSeconds())->toBe(45);
});

it('honors a configured managed-server ready timeout', function () {
    config()->set('pest-e2e.server.ready_timeout_seconds', 90);

    expect(ServerRunner::readyTimeoutSeconds())->toBe(90);
});

it('coerces numeric strings for the managed-server ready timeout', function () {
    config()->set('pest-e2e.server.ready_timeout_seconds', '20');

    expect(ServerRunner::readyTimeoutSeconds())->toBe(20);
});

it('falls back to 45 when the configured ready timeout is not a positive integer', function (mixed $bad) {
    config()->set('pest-e2e.server.ready_timeout_seconds', $bad);

    expect(ServerRunner::readyTimeoutSeconds())->toBe(45);
})->with([
    'zero' => [0],
    'negative' => [-5],
    'non-numeric string' => ['soon'],
    'boolean false' => [false],
    'null' => [null],
]);

it('publishes ready_timeout_seconds default of 45 in the shipped config file', function () {
    $shipped = require __DIR__.'/../../config/pest-e2e.php';

    expect($shipped)
        ->toHaveKey('server')
        ->and($shipped['server'])
        ->toHaveKey('ready_timeout_seconds')
        ->and($shipped['server']['ready_timeout_seconds'])
        ->toBe(45);
});

it('does not inject PHP_CLI_SERVER_WORKERS into the managed process environment', function () {
    $env = ServerRunner::buildProcessEnvironment(
        baseUrl: 'http://127.0.0.1:8801',
        basePath: '/tmp',
        publicPath: '/tmp/public',
    );

    expect(array_key_exists('PHP_CLI_SERVER_WORKERS', $env))
        ->toBeFalse('ServerRunner must not inject PHP_CLI_SERVER_WORKERS into the child environment; PHP 8.5 rejects "1" with "number of workers must be larger than 1" on stderr.');
});

it('drops PHP_CLI_SERVER_WORKERS even when the caller inherited it', function () {
    $original = $_ENV['PHP_CLI_SERVER_WORKERS'] ?? null;
    $_ENV['PHP_CLI_SERVER_WORKERS'] = '4';

    try {
        $env = ServerRunner::buildProcessEnvironment(
            baseUrl: 'http://127.0.0.1:8801',
            basePath: '/tmp',
            publicPath: '/tmp/public',
        );

        expect(array_key_exists('PHP_CLI_SERVER_WORKERS', $env))->toBeFalse();
    } finally {
        if ($original === null) {
            unset($_ENV['PHP_CLI_SERVER_WORKERS']);
        } else {
            $_ENV['PHP_CLI_SERVER_WORKERS'] = $original;
        }
    }
});

it('preserves the useful readiness failure message and reports the configured timeout', function () {
    // Assign a real, still-running process that will never open our expected port,
    // then invoke the private waitUntilReady() with a tight configured timeout.
    $runner = ServerRunner::instance(ServerRunnerType::PHP_BUILTIN);

    // Assign to a port that no server is listening on so tcpResponds() keeps failing.
    $portProperty = new ReflectionProperty($runner, 'port');
    $portProperty->setValue($runner, 1);

    // Keep the process alive for the duration of the wait but never bind the port.
    $process = new Process(['sleep', '5']);
    $process->setTimeout(null);
    $process->start();

    $processProperty = new ReflectionProperty($runner, 'process');
    $processProperty->setValue($runner, $process);

    config()->set('pest-e2e.server.ready_timeout_seconds', 1);

    $waitMethod = new ReflectionMethod($runner, 'waitUntilReady');

    try {
        expect(fn () => $waitMethod->invoke($runner, ServerRunner::readyTimeoutSeconds()))
            ->toThrow(RuntimeException::class, 'did not become ready within 1s at http://127.0.0.1:1');
    } finally {
        $process->stop(0);
        $processProperty->setValue($runner, null);
    }
});

it('preserves parallel server isolation when the parallel worker context requests a port', function () {
    // Simulate a Paratest/Pest parallel worker.
    $_ENV['TEST_TOKEN'] = '3';
    $_SERVER['TEST_TOKEN'] = '3';
    putenv('TEST_TOKEN=3');

    try {
        $runner = ServerRunner::instance(ServerRunnerType::PHP_BUILTIN);
        $resolveMethod = new ReflectionMethod($runner, 'resolvePort');
        $port = $resolveMethod->invoke($runner);

        $base = (int) (config('pest-e2e.parallel.base_port') ?? config('pest-e2e.server.port', 8800));
        expect($port)->toBe($base + 3);
    } finally {
        unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN']);
        putenv('TEST_TOKEN');
    }
});
