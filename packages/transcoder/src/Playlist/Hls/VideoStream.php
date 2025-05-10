<?php

namespace Baander\Transcoder\Playlist\Hls;

class VideoStream
{
    private int $bandwidth;
    private string $uri;
    private ?string $resolution;
    private ?string $codecs;

    public function __construct(
        int $bandwidth,
        string $uri,
        ?string $resolution = null,
        ?string $codecs = null
    ) {
        $this->bandwidth = $bandwidth;
        $this->uri = $uri;
        $this->resolution = $resolution;
        $this->codecs = $codecs;
    }

    public function toString(): string
    {
        $stream = sprintf(
            '#EXT-X-STREAM-INF:BANDWIDTH=%d',
            $this->bandwidth
        );
        if ($this->resolution) {
            $stream .= sprintf(',RESOLUTION=%s', $this->resolution);
        }
        if ($this->codecs) {
            $stream .= sprintf(',CODECS="%s"', $this->codecs);
        }
        $stream .= "\n" . $this->uri;

        return $stream;
    }
}