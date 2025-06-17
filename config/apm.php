<?php
// config/apm.php

return [
    'enabled' => env('ELASTIC_APM_ENABLED', true),
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
        'capture_env' => env('APM_CAPTURE_ENV', true),
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

    'database' => [
      'truncate_sql_length' => 1000,
      'slow_query_threshold_ms' => 1000,
    ],

    'monitoring' => [
        'auth' => env('APM_MONITOR_AUTH', true),
        'database' => env('APM_MONITOR_DATABASE', true),
        'eloquent_advanced' => env('APM_MONITOR_ELOQUENT_ADVANCED', true),
        'eloquent_events' => env('APM_MONITOR_ELOQUENT_EVENTS', true),
        'cache' => env('APM_MONITOR_CACHE', true),
        'filesystem' => env('APM_MONITOR_FILESYSTEM', true),
        'filesystem_uploads' => env('APM_MONITOR_FILESYSTEM_UPLOADS', true),
        'filesystem_downloads' => env('APM_MONITOR_FILESYSTEM_DOWNLOADS', true),
        'http_client' => env('APM_MONITOR_HTTP_CLIENT', true),
        'redis' => env('APM_MONITOR_REDIS', true),
        'queue' => env('APM_MONITOR_QUEUE', false),
        'always_sample_responses' => (bool)env('APM_ALWAYS_SAMPLE_RESPONSES', false),
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

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'filesystem' => env('APM_LOG_FILESYSTEM', true),
        'metrics' => env('APM_LOG_METRICS', false),
        'database' => env('APM_LOG_DATABASE', false),
        'cache' => env('APM_LOG_CACHE', false),
    ],
    /*
    |--------------------------------------------------------------------------
    | Performance Thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'filesystem_slow_operation' => env('APM_FILESYSTEM_SLOW_MS', 1000), // 1 second
        'filesystem_large_file' => env('APM_FILESYSTEM_LARGE_MB', 50), // 50 MB
        'database_slow_query' => env('APM_DATABASE_SLOW_MS', 500), // 500ms
        'cache_slow_operation' => env('APM_CACHE_SLOW_MS', 100), // 100ms
    ],
    /*
    |--------------------------------------------------------------------------
    | Sampling Configuration
    |--------------------------------------------------------------------------
    */
    'sampling' => [
        'filesystem' => env('APM_FILESYSTEM_SAMPLING_RATE', 1.0), // 100% sampling
        'filesystem_large_files' => env('APM_FILESYSTEM_LARGE_FILES_SAMPLING_RATE', 1.0), // 100% for large files
        'database' => env('APM_DATABASE_SAMPLING_RATE', 1.0),
        'cache' => env('APM_CACHE_SAMPLING_RATE', 0.1), // 10% sampling for cache
    ],
    /*
    |--------------------------------------------------------------------------
    | File Type Configuration
    |--------------------------------------------------------------------------
    */
    'file_types' => [
        'track_extensions' => [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'zip', 'rar', '7z', 'tar', 'gz',
            'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp',
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm',
            'mp3', 'wav', 'flac', 'aac', 'ogg',
            'txt', 'csv', 'json', 'xml', 'log',
        ],
        'ignore_extensions' => [
            'css', 'js', 'ico', 'woff', 'woff2', 'ttf', 'eot',
        ],
    ],

];