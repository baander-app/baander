<?php

namespace Baander\ReSpool\Rpc;

use Baander\Common\Microservices\IStreamService;
use Baander\Common\Streaming\TextTrackMode;

class StreamService implements IStreamService
{
    public function getHlsMasterPlaylist(
        string $mediaId,
        bool $adaptive = true
    ): string {
        return 'getHlsMasterPlaylist - response';
    }

    public function getHlsPlaylist(
        string $mediaId,
        string $sessionId,
        ?int $width = null,
        ?int $height = null,
        ?int $subtitleIndex = null,
        ?TextTrackMode $textTrackMode = null,
    ): string {
        return 'getHlsPlaylist - response';
    }

    public function getHlsVideoSegment(
        string $mediaId,
        string $sessionId,
        string $playlistId,
        int $segmentId,
        string $container,
        int $runtimeMs,
        string $audioCodec,
        ?int $audioChannels,
        ?string $profile,
        ?string $level,
        ?int $startTimeMs,
        ?int $width,
        ?int $height,
        ?int $subtitleStreamIndex,
        ?TextTrackMode $textTrackMode,
    ): string {
        return 'getHlsVideoSegment - response';
    }

    public function getHlsAudioSegment(
        string $mediaId,
        string $sessionId,
        string $playlistId,
        int $segmentId,
        string $codec,
        ?int $channels,
        ?string $profile,
        ?string $level,
        ?int $startTimeMs,
        ?int $durationMs,
    ): string {
        return 'getHlsAudioSegment - response';
    }
}