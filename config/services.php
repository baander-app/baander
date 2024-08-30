<?php

return [
    'ffmpeg' => [
        'bin' => [
            'ffmpeg' => env('FFMPEG_BIN', '/usr/bin/ffmpeg'),
            'ffprobe' => env('FFPROBE_BIN', '/usr/bin/ffprobe'),
        ]
    ]
];
