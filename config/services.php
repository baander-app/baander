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
    'discogs'   => [
        'api_key' => env('DISCOGS_API_KEY'),
    ],
    'lastfm'    => [
        'key'    => env('LASTFM_API_KEY'),
        'secret' => env('LASTFM_SECRET'),
    ],
    'spotify'   => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'secret'    => env('SPOTIFY_SECRET'),
    ],
];
