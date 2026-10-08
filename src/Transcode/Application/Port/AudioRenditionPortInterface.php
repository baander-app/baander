<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\Exception\AudioRenditionBusyException;
use App\Transcode\Application\Exception\AudioRenditionFailedException;

/**
 * Cached on-the-fly audio transcoding for tracks.
 *
 * One rendition is cached per track, format and bitrate. When it is complete,
 * callers get the cached file. Otherwise the call starts the encode, or joins
 * the encode already running for that rendition, and gets a progressive stream.
 * Only one encode runs per rendition at a time.
 *
 * The caller decides the bitrate, including any server-wide cap, and must have
 * checked the listener's access to the track first.
 */
interface AudioRenditionPortInterface
{
    /**
     * @param string $trackKey   stable identifier of the track (its public ID)
     * @param string $sourcePath absolute path of the original audio file
     * @param int    $bitrate    target bitrate in bits per second; it snaps down to the format's bitrate ladder
     *
     * @throws AudioRenditionBusyException   when a new encode is needed but the server-wide encode limit is reached
     * @throws AudioRenditionFailedException when the encode fails before any audio is produced or cannot start
     */
    public function open(string $trackKey, string $sourcePath, AudioRenditionFormat $format, int $bitrate): AudioRendition;
}
