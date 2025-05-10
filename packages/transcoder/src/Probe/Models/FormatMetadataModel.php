<?php

namespace Baander\Transcoder\Probe\Models;

/**
 * Represents format-level metadata for the media file.
 */
final class FormatMetadataModel
{
    public readonly string $filename;
    public readonly ?int $size;
    public readonly ?float $duration;
    public readonly ?int $bitRate;
    public readonly ?int $streamCount;
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