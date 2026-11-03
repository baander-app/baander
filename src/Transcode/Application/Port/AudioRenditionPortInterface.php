<?php

declare(strict_types=1);

namespace App\Transcode\Application\Port;

use App\Transcode\Application\Exception\AudioRenditionBusyException;
use App\Transcode\Application\Exception\AudioRenditionFailedException;

/**
 * Owned audio presentations with read-only completed-artifact lookup.
 *
 * Encoded renditions may stream progressively. AAC container copies become
 * readable only after the worker publishes a completed MP4 file. Callers must
 * authorize access to the track before looking up or requesting a rendition.
 */
interface AudioRenditionPortInterface
{
    /** Find a completed AAC stream copy without admitting work. */
    public function findCompleteCopy(string $trackKey, string $sourcePath, string $sourceHash, int $streamIndex): ?AudioRendition;

    /**
     * Copy selected AAC audio to a completed, Range-capable MP4 file.
     *
     * @throws AudioRenditionBusyException   when every audio slot stays busy for the whole start wait; the copy stays queued
     * @throws AudioRenditionFailedException when the source changes, copying fails or work cannot complete
     */
    public function openCopy(string $trackKey, string $sourcePath, string $sourceHash, int $streamIndex): AudioRendition;

    /** Find a complete, current artifact without admitting or repairing work. */
    public function findComplete(string $trackKey, string $sourcePath, AudioRenditionFormat $format, int $bitrate): ?AudioRendition;

    /**
     * @param string $trackKey   stable identifier of the track (its public ID)
     * @param string $sourcePath absolute path of the original audio file
     * @param int    $bitrate    target bitrate in bits per second; it snaps down to the format's bitrate ladder
     *
     * @throws AudioRenditionBusyException   when a new encode is needed and every audio slot stays busy for the whole start wait; the encode stays queued
     * @throws AudioRenditionFailedException when the encode fails before any audio is produced or cannot start
     */
    public function open(string $trackKey, string $sourcePath, AudioRenditionFormat $format, int $bitrate): AudioRendition;
}
