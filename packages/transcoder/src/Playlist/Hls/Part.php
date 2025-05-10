<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents a partial segment (#EXT-X-PART).
 */
class Part
{
    private string $uri;
    private float $duration;
    private ?string $independent = null;
    private ?Byterange $byterange = null;

    public function __construct(string $uri, float $duration, ?bool $independent = null, ?Byterange $byterange = null)
    {
        $this->uri = $uri;
        $this->duration = $duration;
        $this->independent = $independent ? 'YES' : null;
        $this->byterange = $byterange;
    }

    public function toString(): string
    {
        $attributes = [
            "URI=\"{$this->uri}\"",
            "DURATION={$this->duration}",
            $this->independent ? "INDEPENDENT={$this->independent}" : null,
            $this->byterange?->toString(),
        ];

        return '#EXT-X-PART:' . implode(',', array_filter($attributes));
    }
}