<?php

declare(strict_types=1);
use ValcuAndrei\PestE2E\Contracts\JsonParserContract;
use ValcuAndrei\PestE2E\Contracts\JsWorkerContract;
use ValcuAndrei\PestE2E\Parsers\PlaywrightParser;
use ValcuAndrei\PestE2E\Workers\Playwright\PlaywrightWorker;

return [
    'bindings' => [
        JsWorkerContract::class => PlaywrightWorker::class,
        JsonParserContract::class => PlaywrightParser::class,
    ],
    'auth' => [
        'ttl_seconds' => 60,
        'route' => '/pest-e2e/auth/login',
        'route_enabled' => env('PEST_E2E_AUTH_ROUTE_ENABLED', false),
        'header' => [
            'name' => 'X-Pest-E2E',
            'value' => '1',
        ],
    ],
    'server' => [
        'driver' => env('PEST_E2E_SERVER_DRIVER', 'php_builtin'), // php_builtin or artisan
    ],
    'parallel' => [
        // Each parallel worker uses base_port + TEST_TOKEN (e.g. 8801, 8802, …).
        'base_port' => (int) env('PEST_E2E_PARALLEL_BASE_PORT', 8800),
    ],
    'reports' => [
        'base_dir' => storage_path('framework/testing/pest-e2e'),
        'prune' => [
            'enabled' => env('PEST_E2E_REPORT_PRUNE_ENABLED', true),
            'keep_runs' => (int) env('PEST_E2E_REPORT_PRUNE_KEEP_RUNS', 50),
            'keep_days' => (int) env('PEST_E2E_REPORT_PRUNE_KEEP_DAYS', 7),
        ],
    ],
    'timing' => [
        'enabled' => env('PEST_E2E_TIMING', false),
    ],
    'js_runner' => [
        'driver' => env('PEST_E2E_JS_RUNNER_DRIVER', 'playwright'),
        'mode' => env('PEST_E2E_JS_RUNNER_MODE', 'cold'),
    ],
    'package_manager' => env('PEST_E2E_PACKAGE_MANAGER', null),
];
