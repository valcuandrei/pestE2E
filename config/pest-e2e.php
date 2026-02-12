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
];
