<?php

namespace Baander\Transcoder\Config;

final class HlsProfiles
{
    public const VIDEO_PROFILES = [
        '240p' => ['resolution' => '426x240', 'bitrate' => '500k'],
        '360p' => ['resolution' => '640x360', 'bitrate' => '800k'],
        '480p' => ['resolution' => '854x480', 'bitrate' => '1200k'],
        '720p' => ['resolution' => '1280x720', 'bitrate' => '2500k'],
        '1080p' => ['resolution' => '1920x1080', 'bitrate' => '5000k'],
        '4k' => ['resolution' => '3840x2160', 'bitrate' => '15000k'],
        '8k' => ['resolution' => '7680x4320', 'bitrate' => '40000k'],
    ];

    public const AUDIO_PROFILES = [
        '64k' => ['bitrate' => '64k'],
        '128k' => ['bitrate' => '128k'],
        '256k' => ['bitrate' => '256k'],
        'surround' => ['bitrate' => '384k'],
    ];
}
