<?php

namespace Baander\Transcoder\Probe\Models;

class AudioStream
{
    public function __construct(
        public readonly string $codec,
        public readonly int $channels,
        public readonly string $channelLayout // Example: "stereo", "5.1", "7.1"
    ) {}
}
