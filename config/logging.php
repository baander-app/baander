<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'single'),
        'trace'   => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to use.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver'            => 'stack',
            'channels'          => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver'               => 'single',
            'path'                 => storage_path('logs/laravel.log'),
            'level'                => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver'               => 'daily',
            'path'                 => storage_path('logs/laravel.log'),
            'level'                => env('LOG_LEVEL', 'debug'),
            'days'                 => 14,
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver'     => 'monolog',
            'level'      => env('LOG_LEVEL', 'error'),
            'handler'    => StreamHandler::class,
            'formatter'  => env('LOG_STDERR_FORMATTER'),
            'with'       => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stdout' => [
            'driver'     => 'monolog',
            'level'      => 'debug',
            'handler'    => StreamHandler::class,
            'formatter'  => env('LOG_STDOUT_FORMATTER'),
            'with'       => [
                'stream' => 'php://stdout',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver'               => 'syslog',
            'level'                => env('LOG_LEVEL', 'debug'),
            'facility'             => LOG_USER,
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver'               => 'errorlog',
            'level'                => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver'  => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'driver' => 'monolog',
            'path'   => storage_path('logs/laravel.log'),
        ],

        'musicbrainz' => [
            'driver'               => 'single',
            'path'                 => storage_path('logs/musicbrainz.log'),
            'level'                => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'jobs' => [
            'driver' => 'single',
            'path'   => storage_path('logs/jobs.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'music_jobs' => [
            'driver' => 'single',
            'path'   => storage_path('logs/music_jobs.log'),
            'level'  => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ]

        //
        //        'php_deprecations' => [
        //            'driver'               => 'single',
        //            'path'                 => storage_path('logs/deprecations.log'),
        //            'level'                => env('LOG_LEVEL', 'debug'),
        //            'replace_placeholders' => true,
        //        ],
        //
        //        'deprecations' => [
        //            'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'php_deprecations'),
        //            'trace'   => env('LOG_DEPRECATIONS_TRACE', false),
        //        ],
    ],

];
