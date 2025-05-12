<?php

namespace Baander\Common\Streaming;

class ClientCapabilities
{
    public function __construct(
        public array $videoCodecs,        // Supported video codecs ['H264', 'VP9']
        public array $audioCodecs,        // Supported audio codecs ['AAC']
        public array $containers,         // Containers ['mp4', 'mkv']
        public array $streamingProtocols, // Protocols ['HLS', 'DASH']
        public int $maxResolutionWidth,   // Max resolution width (e.g., 1280)
        public int $maxResolutionHeight,  // Max resolution height (e.g., 720)
        public int $maxBitrate            // Max bitrate in kbps (e.g., 3000)
    ) { }
}
