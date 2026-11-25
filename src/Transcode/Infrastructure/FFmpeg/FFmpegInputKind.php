<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\FFmpeg;

use App\Shared\Domain\ValueObject\MediaContainer;
use App\Shared\Domain\ValueObject\MediaKind;

/** What one FFmpeg or ffprobe input is, which decides the demuxers allowed to open it. */
enum FFmpegInputKind
{
    case LibraryAudio;
    case LibraryVideo;
    /** The DASH manifest a worker published, with its fragmented MP4 tracks. */
    case PublishedPresentation;
    /** A `lavfi` source written by the application, such as a hardware probe's test frame. */
    case GeneratedSource;

    /** The comma-separated demuxers FFmpeg may pick, or null for a forced `lavfi` input. */
    public function formatWhitelist(): ?string
    {
        return match ($this) {
            self::LibraryAudio => implode(',', MediaContainer::demuxers(MediaKind::Audio)),
            self::LibraryVideo => implode(',', MediaContainer::demuxers(MediaKind::Video)),
            self::PublishedPresentation => 'dash,mov',
            self::GeneratedSource => null,
        };
    }
}
