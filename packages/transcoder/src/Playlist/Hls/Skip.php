<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents a skip tag for allowing clients to jump over segments (#EXT-X-SKIP).
 * Allows clients to skip over redundant segments, particularly for live events
 */
class Skip
{
    private int $skippedSegments;

    public function __construct(int $skippedSegments)
    {
        $this->skippedSegments = $skippedSegments;
    }

    public function toString(): string
    {
        return "#EXT-X-SKIP:SKIPPED-SEGMENTS={$this->skippedSegments}";
    }
}