<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents a preloading hint for the client (#EXT-X-PRELOAD-HINT).
 * This tag is used to provide a hint to the client about the URI of the next segment or part before it is available.
 */
class PreloadHint
{
    private string $type;
    private string $uri;
    private ?Byterange $byterange = null;

    public function __construct(string $type, string $uri, ?Byterange $byterange = null)
    {
        $this->type = $type;
        $this->uri = $uri;
        $this->byterange = $byterange;
    }

    public function toString(): string
    {
        $attributes = [
            "TYPE={$this->type}",
            "URI=\"{$this->uri}\"",
            $this->byterange?->toString(),
        ];

        return '#EXT-X-PRELOAD-HINT:' . implode(',', array_filter($attributes));
    }
}