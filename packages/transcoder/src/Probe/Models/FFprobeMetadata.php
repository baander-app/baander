<?php

namespace Baander\Transcoder\Probe\Models;

/**
 * Unified object for all FFprobe metadata.
 * Includes streams and format information.
 */
final class FFprobeMetadata
{
    /**
     * @var Stream[] List of all streams (video, audio, etc.)
     */
    public readonly array $streams;

    /**
     * Format-specific metadata from FFprobe.
     */
    public readonly ?Format $format;

    public function __construct(array $streams, ?Format $format)
    {
        $this->streams = $streams;
        $this->format = $format;
    }
}