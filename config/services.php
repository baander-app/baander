<?php

return [
    'ffmpeg'    => [
        'bin' => [
            'ffmpeg'  => env('FFMPEG_BIN', '/usr/bin/ffmpeg'),
            'ffprobe' => env('FFPROBE_BIN', '/usr/bin/ffprobe'),
        ],
    ],
    'tastedive' => [
        'api_key' => env('TASTE_DIVE_API_KEY'),
    ],
    'respool'   => [
        'host'             => 'http://127.0.0.1:43243',
        'certificate_path' => realpath(__DIR__ . '/../services/re-spool/localhost.pem'),
    ],
];
