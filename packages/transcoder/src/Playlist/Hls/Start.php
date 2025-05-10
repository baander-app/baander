<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents a playback start (#EXT-X-START).
 */
class Start
{
    private float $timeOffset;
    private bool $precise;

    public function __construct(float $timeOffset, bool $precise = false)
    {
        $this->timeOffset = $timeOffset;
        $this->precise = $precise;
    }

    public function toString(): string
    {
        $attributes = [
            "TIME-OFFSET={$this->timeOffset}",
            $this->precise ? 'PRECISE=YES' : null,
        ];

        return '#EXT-X-START:' . implode(',', array_filter($attributes));
    }
}
