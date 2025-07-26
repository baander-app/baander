<?php

return [
    'instrumentation' => [
        'enabled' => env('OTEL_INSTRUMENTATION_ENABLED', true),

        'events' => [
            'auth'       => env('OTEL_TRACE_AUTH_EVENTS', true),
            'mail'       => env('OTEL_TRACE_MAIL_EVENTS', true),
            'queue'      => env('OTEL_TRACE_QUEUE_EVENTS', true),
            'database'   => env('OTEL_TRACE_DATABASE_EVENTS', true),
            'routing'    => env('OTEL_TRACE_ROUTING_EVENTS', true),
            'cache'      => env('OTEL_TRACE_CACHE_EVENTS', true),
            'filesystem' => env('OTEL_TRACE_FILESYSTEM_EVENTS', true),
            'security'   => env('OTEL_TRACE_SECURITY_EVENTS', true),
            'custom'     => env('OTEL_TRACE_CUSTOM_EVENTS', true),
        ],
    ],

    'database' => [
        'trace_all_queries'    => env('OTEL_TRACE_ALL_QUERIES', false),
        'slow_query_threshold' => env('OTEL_SLOW_QUERY_THRESHOLD', 100), // milliseconds
    ],

    'ignored_paths' => [
        '/health',
        '/metrics',
        '/favicon.ico',
        '/robots.txt',
        '/_debugbar',
        '/telescope',
        '/horizon',
    ],

    'security' => [
        'track_failed_logins'       => env('OTEL_TRACK_FAILED_LOGINS', true),
        'track_rate_limits'         => env('OTEL_TRACK_RATE_LIMITS', true),
        'track_unauthorized_access' => env('OTEL_TRACK_UNAUTHORIZED_ACCESS', true),
    ],
];