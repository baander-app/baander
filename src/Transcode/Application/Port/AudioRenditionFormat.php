<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

/**
 * The audio formats a track can be transcoded to on the fly.
 *
 * These are the formats the track stream endpoint documents for its `format`
 * parameter. Each one is written in a container that can be read while it is
 * still being encoded, so the first listener does not wait for the whole file.
 */
enum AudioRenditionFormat: string
{
    case Opus = 'opus';
    case Aac = 'aac';
    case Mp3 = 'mp3';

    public function mimeType(): string
    {
        return match ($this) {
            self::Opus => 'audio/ogg',
            self::Aac => 'audio/aac',
            self::Mp3 => 'audio/mpeg',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Opus => 'opus',
            self::Aac => 'aac',
            self::Mp3 => 'mp3',
        };
    }

    /**
     * Fit a requested bitrate (bits per second) to what the encoder supports,
     * in whole kilobits so equivalent requests share one cached rendition.
     */
    public function supportedBitrate(int $bitrate): int
    {
        [$minimum, $maximum] = match ($this) {
            self::Opus => [32_000, 256_000],
            self::Aac, self::Mp3 => [32_000, 320_000],
        };

        return intdiv(max($minimum, min($maximum, $bitrate)), 1000) * 1000;
    }
}
