<?php

namespace Baander\Transcoder\Probe\Models;

final class MediaInfo
{
    /**
     * @param VideoStream[] $videoStreams
     * @param AudioStream[] $audioStreams
     */
    public function __construct(
        public readonly array $videoStreams,
        public readonly array $audioStreams
    ) {}
}
