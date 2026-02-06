<?php

declare(strict_types=1);

return [
    'auth' => [
        'ttl_seconds' => 60,
        'route' => '/.well-known/pest-e2e/auth/login',
        'header' => [
            'name' => 'X-Pest-E2E',
            'value' => '1',
        ],
    ],
];
