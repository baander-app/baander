<?php

namespace Baander\Transcoder\Probe\Models;

class VideoStream
{
    public function __construct(
        public readonly string $codec,
        public readonly int $width,
        public readonly int $height,
        public readonly float $duration,
        public readonly int $avgFrameRate,
        public readonly int $bitrate,
        public readonly string $frameRate // Example: "30/1" or "24000/1001"
    ) {}
}
