<?php

declare(strict_types=1);

return [
    'auth' => [
        'ttl_seconds' => 60,
        'route' => '/pest-e2e/auth/login',
        'route_enabled' => env('PEST_E2E_AUTH_ROUTE_ENABLED', false),
        'header' => [
            'name' => 'X-Pest-E2E',
            'value' => '1',
        ],
    ],
    'reports' => [
        'dir' => env('PEST_E2E_REPORTS_DIR', storage_path('framework/testing/pest-e2e')),
        'prune' => [
            'enabled' => env('PEST_E2E_PRUNE_ENABLED', true),
            'unit' => env('PEST_E2E_PRUNE_UNIT', 'days'), // days, items
            'value' => env('PEST_E2E_PRUNE_VALUE', 30),
        ],
    ],
    'server' => [
        'driver' => env('PEST_E2E_SERVER_DRIVER', 'artisan'), // artisan - No other options yet
    ],
    'timing' => [
        'enabled' => env('PEST_E2E_TIMING', false),
    ],
    'js_runner' => [
        'driver' => env('PEST_E2E_JS_RUNNER_DRIVER', 'playwright'),
        'mode' => env('PEST_E2E_JS_RUNNER_MODE', 'cold'),
    ],
];
