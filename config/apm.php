<?php
// config/apm.php

return [
    'enabled' => env('APM_ENABLED', true),
    'sampling_rate' => env('APM_SAMPLING_RATE', 1.0),
    'service_name' => env('APM_SERVICE_NAME', 'bander-app'),
    'service_version' => env('APM_SERVICE_VERSION', '1.0.0'),
    'environment' => env('APP_ENV', 'production'),
    'server_url' => env('ELASTIC_APM_SERVER_URL'),
    'secret_token' => env('ELASTIC_APM_SECRET_TOKEN'),

    'transaction' => [
        'max_spans' => env('APM_MAX_SPANS', 500),
        'stack_trace_limit' => env('APM_STACK_TRACE_LIMIT', 50),
        'capture_body' => env('APM_CAPTURE_BODY', 'errors'),
    ],

    'ignore_patterns' => [
        'routes' => [
            '/health*',
            '/metrics*',
            '/_ignition*',
            '/telescope*',
            '/horizon*',
        ],
        'user_agents' => [
            'kube-probe*',
            'GoogleHC*',
            'ELB-HealthChecker*',
        ],
    ],

    'context' => [
        'capture_headers' => env('APM_CAPTURE_HEADERS', true),
        'capture_env' => env('APM_CAPTURE_ENV', false),
        'sanitize_field_names' => [
            'password',
            'passwd',
            'pwd',
            'secret',
            'token',
            'key',
            'auth',
            'credit',
            'card',
            'authorization',
            'cookie',
            'session',
        ],
    ],

    'monitoring' => [
        'auth' => env('APM_MONITOR_AUTH', true),
        'database' => env('APM_MONITOR_DATABASE', true),
        'cache' => env('APM_MONITOR_CACHE', true),
        'http_client' => env('APM_MONITOR_HTTP_CLIENT', true),
        'redis' => env('APM_MONITOR_REDIS', true),
        'queue' => env('APM_MONITOR_QUEUE', true),
    ],

    'swoole' => [
        'monitor_workers' => env('APM_SWOOLE_MONITOR_WORKERS', true),
        'monitor_coroutines' => env('APM_SWOOLE_MONITOR_COROUTINES', true),
        'worker_metrics_interval' => env('APM_SWOOLE_METRICS_INTERVAL', 60), // seconds
        'max_coroutine_spans' => env('APM_SWOOLE_MAX_COROUTINE_SPANS', 100),
    ],

    'performance' => [
        'slow_request_threshold_ms' => env('APM_SLOW_REQUEST_THRESHOLD', 2000),
        'memory_threshold_mb' => env('APM_MEMORY_THRESHOLD', 256),
    ],
];