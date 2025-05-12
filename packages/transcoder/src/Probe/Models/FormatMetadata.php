<?php

namespace Baander\Transcoder\Probe\Models;

/**
 * Represents overall file metadata retrieved by FFprobe.
 */
final class FormatMetadata
{
    /**
     * Path to the analyzed file.
     */
    public readonly string $filename;

    /**
     * File size in bytes.
     */
    public readonly ?int $size;

    /**
     * Total duration of the file in seconds.
     */
    public readonly ?float $duration;

    /**
     * Total bitrate of the file in bits per second.
     */
    public readonly ?int $bitRate;

    /**
     * Total number of streams in the file.
     */
    public readonly ?int $streamCount;

    /**
     * File-level tags (e.g., title, artist, etc.)
     */
    public readonly ?array $tags;

    public function __construct(
        string $filename,
        ?int $size = null,
        ?float $duration = null,
        ?int $bitRate = null,
        ?int $streamCount = null,
        ?array $tags = null
    ) {
        $this->filename = $filename;
        $this->size = $size;
        $this->duration = $duration;
        $this->bitRate = $bitRate;
        $this->streamCount = $streamCount;
        $this->tags = $tags;
    }
}