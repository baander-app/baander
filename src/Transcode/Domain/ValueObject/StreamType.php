<?php

declare(strict_types=1);

namespace App\Transcode\Domain\ValueObject;

/**
 * Stream type classification for separate audio/video/subtitle encoding.
 */
enum StreamType: string
{
    case Video = 'video';
    case Audio = 'audio';
    case Subtitle = 'subtitle';

    /**
     * Return the FFmpeg flag to exclude this stream type.
     *
     * - Video: `-an` (no audio — video-only output)
     * - Audio: `-vn` (no video — audio-only output)
     * - Subtitle: `-vn -an` (no video, no audio — subtitle extraction)
     */
    public function ffmpegExcludeFlag(): string
    {
        return match ($this) {
            self::Video => '-an',
            self::Audio => '-vn',
            self::Subtitle => '-vn -an',
        };
    }

    /**
     * Return the MOV/MP4 muxing flags for this stream type.
     *
     * Video and audio use CMAF fragmented MP4 flags.
     * Subtitle extraction produces WebVTT — no MP4 muxing.
     */
    public function ffmpegOutputFlags(): string
    {
        return match ($this) {
            self::Video, self::Audio => '-movflags +frag_keyframe+separate_moof+default_base_moof -f mp4',
            self::Subtitle => '-f webvtt',
        };
    }
}
