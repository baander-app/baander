<?php

namespace Baander\Transcoder\Playlist\Hls;

/**
 * Represents a byte range (#EXT-X-BYTERANGE).
 */
class Byterange
{
    private int $length;
    private ?int $offset;

    public function __construct(int $length, ?int $offset = null)
    {
        $this->length = $length;
        $this->offset = $offset;
    }

    public function toString(): string
    {
        $range = $this->length;

        if ($this->offset !== null) {
            $range .= "@{$this->offset}";
        }

        return "#EXT-X-BYTERANGE:{$range}";
    }
}
