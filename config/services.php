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
    'discogs' => [
        'api_key' => env('DISCOGS_API_KEY'),
    ]
];
