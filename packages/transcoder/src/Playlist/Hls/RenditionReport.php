<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents a rendition report (#EXT-X-RENDITION-REPORT)
 * For Low-Latency HLS, this tag provides synchronization information for alternate renditions.
 */
class RenditionReport
{
    private string $uri;
    private int $lastMediaSequence;
    private ?int $lastPartIndex;

    public function __construct(string $uri, int $lastMediaSequence, ?int $lastPartIndex = null)
    {
        $this->uri = $uri;
        $this->lastMediaSequence = $lastMediaSequence;
        $this->lastPartIndex = $lastPartIndex;
    }

    public function toString(): string
    {
        $attributes = [
            "URI=\"{$this->uri}\"",
            "LAST-MSN={$this->lastMediaSequence}",
            $this->lastPartIndex !== null ? "LAST-PART={$this->lastPartIndex}" : null,
        ];

        return '#EXT-X-RENDITION-REPORT:' . implode(',', array_filter($attributes));
    }
}
