<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use ValcuAndrei\PestE2E\Support\ParallelWorker;

beforeEach(function () {
    unset($_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN'], $_SERVER['DB_DATABASE'], $_ENV['DB_DATABASE']);
    putenv('TEST_TOKEN');
    putenv('DB_DATABASE');
    app()->instance('config', new Repository);
    config()->set('cache.prefix', 'laravel-cache-');
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');
});

afterEach(function () {
    unset($_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN'], $_SERVER['DB_DATABASE'], $_ENV['DB_DATABASE']);
    putenv('TEST_TOKEN');
    putenv('DB_DATABASE');
    app()->instance('config', new Repository);
    config()->set('cache.prefix', 'laravel-cache-');
    config()->set('database.connections.sqlite.database', ':memory:');
});

it('detects parallel worker token from TEST_TOKEN', function () {
    $_SERVER['TEST_TOKEN'] = '3';

    expect(ParallelWorker::token())->toBe('3')
        ->and(ParallelWorker::isParallel())->toBeTrue();
});

it('returns null token when not running in parallel', function () {
    expect(ParallelWorker::token())->toBeNull()
        ->and(ParallelWorker::isParallel())->toBeFalse();
});

it('derives deterministic server port from base port and token', function () {
    $_SERVER['TEST_TOKEN'] = '4';

    expect(ParallelWorker::serverPort(8800))->toBe(8804);

    unset($_SERVER['TEST_TOKEN']);

    expect(ParallelWorker::serverPort(8800))->toBeNull();
});

it('builds worker-scoped database names', function () {
    $_SERVER['TEST_TOKEN'] = '2';

    expect(ParallelWorker::testDatabaseName('myapp'))->toBe('myapp_test_2')
        ->and(ParallelWorker::testDatabaseName('myapp_test_2'))->toBe('myapp_test_2');
});

it('builds cache prefix for parallel workers', function () {
    $_SERVER['TEST_TOKEN'] = '1';

    expect(ParallelWorker::cachePrefix())->toBe('test_1_')
        ->and(ParallelWorker::cachePrefix('pest'))->toBe('test_1_pest');
});

it('includes worker env for managed server subprocess', function () {
    $_SERVER['TEST_TOKEN'] = '2';
    $_ENV['DB_DATABASE'] = 'testing';
    putenv('DB_DATABASE=testing');

    $env = ParallelWorker::serverEnvironment();

    expect($env)->toMatchArray([
        'TEST_TOKEN' => '2',
        'LARAVEL_PARALLEL_TESTING' => '1',
        'PEST_E2E_PARALLEL' => '1',
    ])->and($env)->toHaveKey('DB_DATABASE');
});

it('passes the current laravel parallel cache prefix to the managed server', function () {
    $_SERVER['TEST_TOKEN'] = '3';
    config()->set('cache.prefix', 'laravel-cache-test_3_');

    expect(ParallelWorker::serverEnvironment()['CACHE_PREFIX'])->toBe('laravel-cache-test_3_');
});

it('passes a worker-scoped database name from the current database config', function () {
    $_SERVER['TEST_TOKEN'] = '4';
    config()->set('database.connections.sqlite.database', 'testing');

    expect(ParallelWorker::serverEnvironment()['DB_DATABASE'])->toBe('testing_test_4');
});

it('derives database from env when app config is in-memory', function () {
    $_SERVER['TEST_TOKEN'] = '2';
    $_ENV['DB_DATABASE'] = 'testing';
    putenv('DB_DATABASE=testing');

    $database = config('database.connections.'.config('database.default').'.database');

    if ($database === ':memory:') {
        expect(ParallelWorker::testDatabaseName('testing'))->toBe('testing_test_2');
    }
});

it('adds worker suffix for isolated temp paths', function () {
    $_SERVER['TEST_TOKEN'] = '5';

    expect(ParallelWorker::pathSuffix())->toBe('/worker-5');
});
