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
     * The bitrates (bits per second) a rendition is encoded at. Every request
     * snaps to one of these, so a track has at most this many renditions per
     * format however many different bitrates listeners ask for.
     */
    public const array BITRATE_LADDER = [64_000, 96_000, 128_000, 160_000, 192_000, 256_000, 320_000];

    /**
     * Snap a requested bitrate (bits per second) down to the highest ladder step
     * the format supports that does not exceed it. A request below the lowest
     * step gets the lowest step.
     */
    public function supportedBitrate(int $bitrate): int
    {
        $maximum = match ($this) {
            self::Opus => 256_000,
            self::Aac, self::Mp3 => 320_000,
        };

        $supported = self::BITRATE_LADDER[0];
        foreach (self::BITRATE_LADDER as $step) {
            if ($step > $bitrate || $step > $maximum) {
                break;
            }
            $supported = $step;
        }

        return $supported;
    }
}
