<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents a variant stream (#EXT-X-STREAM-INF).
 */
class Stream
{
    private int $bandwidth;
    private string $uri;
    private ?string $resolution = null;
    private ?string $codecs = null;

    public function __construct(int $bandwidth, string $uri)
    {
        $this->bandwidth = $bandwidth;
        $this->uri = $uri;
    }

    public function setResolution(string $resolution): self
    {
        $this->resolution = $resolution;
        return $this;
    }

    public function setCodecs(string $codecs): self
    {
        $this->codecs = $codecs;
        return $this;
    }

    public function toString(): string
    {
        $attributes = [
            "BANDWIDTH={$this->bandwidth}",
            $this->resolution ? "RESOLUTION={$this->resolution}" : null,
            $this->codecs ? "CODECS=\"{$this->codecs}\"" : null,
        ];

        $attributes = array_filter($attributes); // Remove null entries.
        return '#EXT-X-STREAM-INF:' . implode(',', $attributes) . "\n{$this->uri}";
    }
}
