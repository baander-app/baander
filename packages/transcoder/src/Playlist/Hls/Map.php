<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents an initialization segment URI and byte range (#EXT-X-MAP).
 */
class Map
{
    private string $uri; // Required: URI of the initialization segment.
    private ?Byterange $byterange = null; // Optional: Byte range.

    public function __construct(string $uri, ?Byterange $byterange = null)
    {
        $this->uri = $uri;
        $this->byterange = $byterange;
    }

    public function toString(): string
    {
        $attributes = [
            "URI=\"{$this->uri}\"",
        ];

        if ($this->byterange) {
            $attributes[] = "BYTERANGE={$this->byterange->toString()}";
        }

        return '#EXT-X-MAP:' . implode(',', $attributes);
    }
}