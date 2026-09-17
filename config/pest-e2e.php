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
        'host' => env('PEST_E2E_SERVER_HOST', '127.0.0.1'),
        'port' => (int) env('PEST_E2E_SERVER_PORT', env('PEST_E2E_PARALLEL_BASE_PORT', 8800)),
        // When true, parallel workers bind to server.port + TEST_TOKEN (e.g. 8801, 8802).
        'parallel_port_offset' => filter_var(env('PEST_E2E_SERVER_PARALLEL_PORT_OFFSET', true), FILTER_VALIDATE_BOOL),
        // Ceiling for how long ServerRunner waits for the managed PHP server to
        // accept its first TCP connection. Only bounds startup — a healthy boot
        // returns immediately, so raising this adds no per-test overhead.
        'ready_timeout_seconds' => (int) env('PEST_E2E_SERVER_READY_TIMEOUT_SECONDS', 45),
    ],
    'parallel' => [
        // Deprecated alias for server.port — kept for backward compatibility.
        'base_port' => (int) env('PEST_E2E_PARALLEL_BASE_PORT', env('PEST_E2E_SERVER_PORT', 8800)),
    ],
    'reports' => [
        // `null` (default) means: use `storage_path('framework/testing/pest-e2e')`
        // when it is writable by the current process; otherwise fall back to a
        // deterministic private directory scoped to the effective UID and the
        // current project root (see ReportPathPolicy).
        //
        // Any non-null string is treated as an explicit directive from the
        // consumer's config: it will be used as-is, and no automatic fallback
        // will be applied if that directory turns out not to be writable.
        'base_dir' => env('PEST_E2E_REPORTS_BASE_DIR'),
        'prune' => [
            'enabled' => env('PEST_E2E_REPORT_PRUNE_ENABLED', true),
            'keep_runs' => (int) env('PEST_E2E_REPORT_PRUNE_KEEP_RUNS', 50),
            'keep_days' => (int) env('PEST_E2E_REPORT_PRUNE_KEEP_DAYS', 7),
        ],
    ],
    // Adaptive resource-based admission controller. Gates heavyweight
    // Playwright executions on measured host pressure so parallel workers
    // do not saturate the box and trip Playwright's own action/navigation
    // timeouts. Faster hardware naturally admits more concurrent work;
    // slower hardware naturally queues earlier — there is no fixed
    // simultaneous-execution cap. Any metric that cannot be measured on
    // the current platform is treated as "not above threshold" so the
    // controller never deadlocks.
    'admission' => [
        'enabled' => env('PEST_E2E_ADMISSION_ENABLED', true),
        // CPU %: above HIGH → queue; drop below LOW to resume from queue.
        'cpu_high_pct' => (float) env('PEST_E2E_ADMISSION_CPU_HIGH_PCT', 80),
        'cpu_low_pct' => (float) env('PEST_E2E_ADMISSION_CPU_LOW_PCT', 75),
        // Memory used %: same shape; on Linux this uses MemAvailable.
        'memory_high_pct' => (float) env('PEST_E2E_ADMISSION_MEMORY_HIGH_PCT', 85),
        'memory_low_pct' => (float) env('PEST_E2E_ADMISSION_MEMORY_LOW_PCT', 80),
        // Sleep inside the flock after admitting so the next worker samples
        // a system that has had time to react.
        'cooldown_ms' => (int) env('PEST_E2E_ADMISSION_COOLDOWN_MS', 500),
        // Sleep between failed admission attempts (plus a random jitter).
        'backoff_ms' => (int) env('PEST_E2E_ADMISSION_BACKOFF_MS', 500),
        'jitter_ms' => (int) env('PEST_E2E_ADMISSION_JITTER_MS', 200),
        // Ceiling: if a worker has been waiting this long and pressure has
        // not dropped, admit-with-warning rather than deadlock the suite.
        'max_wait_seconds' => (int) env('PEST_E2E_ADMISSION_MAX_WAIT_SECONDS', 300),
        // /proc/stat delta sampling interval (Linux sampler only).
        'cpu_sample_interval_ms' => (int) env('PEST_E2E_ADMISSION_CPU_SAMPLE_INTERVAL_MS', 100),
    ],
    // Per-ParaTest-worker persistent Playwright browser server. First e2e()
    // call on a worker launches a headless chromium via
    // `chromium.launchServer()`; subsequent invocations connect to the same
    // browser (each with its own fresh isolated context). Terminated at
    // TestSuite\Finished and PHP shutdown. Enables the connect path via
    // PEST_E2E_WARM_WS_ENDPOINT, which the shipped playwright.config.js
    // stub already reads via use.connectOptions.wsEndpoint.
    'warm_browser' => [
        'enabled' => env('PEST_E2E_WARM_BROWSER_ENABLED', true),
        'startup_timeout_seconds' => (int) env('PEST_E2E_WARM_BROWSER_STARTUP_TIMEOUT_SECONDS', 30),
    ],
    'timing' => [
        'enabled' => env('PEST_E2E_TIMING', false),
    ],
    'js_runner' => [
        'driver' => env('PEST_E2E_JS_RUNNER_DRIVER', 'playwright'),
        'mode' => env('PEST_E2E_JS_RUNNER_MODE', 'cold'),
    ],
    'package_manager' => env('PEST_E2E_PACKAGE_MANAGER', null),
    'agent_output' => env('PEST_E2E_AGENT_OUTPUT', env('PAO_FORCE', false)),
];
