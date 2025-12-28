<?php

return [
    'ffmpeg'    => [
        'bin' => [
            'ffmpeg'  => env('FFMPEG_BIN', '/usr/bin/ffmpeg'),
            'ffprobe' => env('FFPROBE_BIN', '/usr/bin/ffprobe'),
        ],
    ],
    'essentia'  => [
        'header_path'   => env('ESSENTIA_HEADER_PATH', 'essentia/headers/essentia_ffi.h'),
        'library_path'  => env('ESSENTIA_LIBRARY_PATH', 'essentia/lib/libessentia.so'),
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
    'transcoder' => [
        'socket_path' => env('TRANSCODER_SOCKET', storage_path('transcoder.sock'))
    ]
];
