<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use ValcuAndrei\PestE2E\Support\ParallelWorker;
use ValcuAndrei\PestE2E\Support\ParallelWorkerContext;

beforeEach(function () {
    unset($_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN'], $_SERVER['DB_DATABASE'], $_ENV['DB_DATABASE']);
    putenv('TEST_TOKEN');
    putenv('DB_DATABASE');
    app()->instance('config', new Repository);
    config()->set('pest-e2e.server.host', '127.0.0.1');
    config()->set('pest-e2e.server.port', 8800);
    config()->set('pest-e2e.server.parallel_port_offset', true);
    config()->set('pest-e2e.parallel.base_port', 8800);
    config()->set('cache.prefix', 'laravel-cache-');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
});

afterEach(function () {
    unset($_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN'], $_SERVER['DB_DATABASE'], $_ENV['DB_DATABASE']);
    putenv('TEST_TOKEN');
    putenv('DB_DATABASE');
    app()->instance('config', new Repository);
});

it('detects parallel worker token from TEST_TOKEN in server superglobal', function () {
    $_SERVER['TEST_TOKEN'] = '3';

    expect(ParallelWorkerContext::token())->toBe('3')
        ->and(ParallelWorkerContext::isParallel())->toBeTrue()
        ->and(ParallelWorkerContext::numericToken())->toBe(3);
});

it('detects parallel worker token from env helper when booted', function () {
    $_ENV['APP_ENV'] = 'testing';
    putenv('TEST_TOKEN=2');

    expect(ParallelWorkerContext::token())->toBe('2');
});

it('returns null token when not running in parallel', function () {
    expect(ParallelWorkerContext::token())->toBeNull()
        ->and(ParallelWorkerContext::isParallel())->toBeFalse()
        ->and(ParallelWorkerContext::numericToken())->toBeNull()
        ->and(ParallelWorkerContext::serverPort(8800))->toBeNull();
});

it('keeps base port unchanged when not parallel', function () {
    expect(ParallelWorkerContext::serverPort(8000))->toBeNull()
        ->and(ParallelWorkerContext::appUrl('127.0.0.1', 8000))->toBe('http://127.0.0.1');
});

it('maps TEST_TOKEN=1 to base port plus one', function () {
    $_SERVER['TEST_TOKEN'] = '1';

    expect(ParallelWorkerContext::serverPort(8000))->toBe(8001)
        ->and(ParallelWorkerContext::appUrl('127.0.0.1', 8000))->toBe('http://127.0.0.1:8001');
});

it('maps TEST_TOKEN=4 to base port plus four', function () {
    $_SERVER['TEST_TOKEN'] = '4';

    expect(ParallelWorkerContext::serverPort(8800))->toBe(8804)
        ->and(ParallelWorkerContext::appUrl('127.0.0.1', 8800))->toBe('http://127.0.0.1:8804');
});

it('reads base port from server.port config', function () {
    $_SERVER['TEST_TOKEN'] = '2';
    config()->set('pest-e2e.server.port', 9000);

    expect(ParallelWorkerContext::serverPort())->toBe(9002);
});

it('disables port offset when parallel_port_offset is false', function () {
    $_SERVER['TEST_TOKEN'] = '2';
    config()->set('pest-e2e.server.parallel_port_offset', false);

    expect(ParallelWorkerContext::serverPort(8800))->toBeNull();
});

it('falls back to ephemeral port mapping when token is non-numeric', function () {
    $_SERVER['TEST_TOKEN'] = 'worker-a';

    expect(ParallelWorkerContext::numericToken())->toBeNull()
        ->and(ParallelWorkerContext::serverPort(8800))->toBeNull();
});

it('builds worker-scoped database names', function () {
    $_SERVER['TEST_TOKEN'] = '2';

    expect(ParallelWorkerContext::testDatabaseName('myapp'))->toBe('myapp_test_2')
        ->and(ParallelWorkerContext::testDatabaseName('myapp_test_2'))->toBe('myapp_test_2')
        ->and(ParallelWorkerContext::testDatabaseName('testing_test_3'))->toBe('testing_test_3');
});

it('includes worker env for managed server subprocess', function () {
    $_SERVER['TEST_TOKEN'] = '2';
    $_ENV['DB_DATABASE'] = 'testing';
    putenv('DB_DATABASE=testing');

    $env = ParallelWorkerContext::serverEnvironment();

    expect($env)->toMatchArray([
        'TEST_TOKEN' => '2',
        'LARAVEL_PARALLEL_TESTING' => '1',
        'PEST_E2E_PARALLEL' => '1',
        'DB_DATABASE' => 'testing_test_2',
    ]);
});

it('passes mysql connection settings from laravel config to the managed server', function () {
    $_SERVER['TEST_TOKEN'] = '3';
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => 'mysql',
        'port' => '3306',
        'database' => 'testing_test_3',
        'username' => 'sail',
        'password' => 'password',
    ]);

    expect(ParallelWorkerContext::serverEnvironment())->toMatchArray([
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'mysql',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'testing_test_3',
        'DB_USERNAME' => 'sail',
        'DB_PASSWORD' => 'password',
    ]);
});

it('passes the current laravel parallel cache prefix to the managed server', function () {
    $_SERVER['TEST_TOKEN'] = '3';
    config()->set('cache.prefix', 'laravel-cache-test_3_');

    expect(ParallelWorkerContext::serverEnvironment()['CACHE_PREFIX'])->toBe('laravel-cache-test_3_');
});

it('does not rewrite an already worker-scoped database from config', function () {
    $_SERVER['TEST_TOKEN'] = '4';
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.database', 'testing_test_4');

    expect(ParallelWorkerContext::serverEnvironment()['DB_DATABASE'])->toBe('testing_test_4');
});

it('adds worker suffix for isolated temp paths', function () {
    $_SERVER['TEST_TOKEN'] = '5';

    expect(ParallelWorkerContext::pathSuffix())->toBe('/worker-5');
});

it('keeps deprecated ParallelWorker facade aligned with ParallelWorkerContext', function () {
    $_SERVER['TEST_TOKEN'] = '1';

    expect(ParallelWorker::token())->toBe(ParallelWorkerContext::token())
        ->and(ParallelWorker::serverPort(8800))->toBe(ParallelWorkerContext::serverPort(8800));
});
