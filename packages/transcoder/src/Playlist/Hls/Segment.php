<?php

namespace Baander\Transcoder\Playlist\Hls;

class Segment
{
    private float $duration; // Required: Duration of the segment.
    private string $uri; // Required: Segment file URI.
    private ?string $title = null; // Optional: Human-readable title.
    private ?Byterange $byterange = null; // Optional: Byte range.
    private bool $discontinuity = false; // Optional: Discontinuity marker.
    private ?string $programDateTime = null; // Optional: Real-world date/time.
    private ?Key $key = null; // Optional: Encryption key.
    private bool $gap = false; // Optional: Skip segment (EXT-X-GAP).
    private ?Map $map = null;

    public function __construct(float $duration, string $uri, ?string $title = null)
    {
        $this->duration = $duration;
        $this->uri = $uri;
        $this->title = $title;
    }

    public function setByterange(Byterange $byterange): self
    {
        $this->byterange = $byterange;
        return $this;
    }

    public function markDiscontinuity(bool $flag = true): self
    {
        $this->discontinuity = $flag;
        return $this;
    }

    public function setProgramDateTime(string $dateTime): self
    {
        $this->programDateTime = $dateTime;
        return $this;
    }

    public function setKey(Key $key): self
    {
        $this->key = $key;
        return $this;
    }

    public function markGap(bool $flag = true): self
    {
        $this->gap = $flag;
        return $this;
    }

    public function setMap(Map $map): self
    {
        $this->map = $map;
        return $this;
    }

    public function toString(): string
    {
        $lines = [];

        if ($this->discontinuity) {
            $lines[] = '#EXT-X-DISCONTINUITY';
        }

        if ($this->programDateTime) {
            $lines[] = "#EXT-X-PROGRAM-DATE-TIME:{$this->programDateTime}";
        }

        if ($this->key) {
            $lines[] = $this->key->toString();
        }

        if ($this->gap) {
            $lines[] = '#EXT-X-GAP';
        }

        if ($this->byterange) {
            $lines[] = $this->byterange->toString();
        }

        if ($this->map) {
            $lines[] = $this->map->toString();
        }

        $lines[] = "#EXTINF:{$this->duration}," . ($this->title ?? '');
        $lines[] = $this->uri;

        return implode("\n", $lines);
    }
}
