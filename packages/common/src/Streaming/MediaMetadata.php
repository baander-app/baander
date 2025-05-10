<?php

namespace Baander\Common\Streaming;

class MediaMetadata
{
    public function __construct(
        public int    $width,
        public int    $height,
        public int    $bitrate,
        public string $codec,
        public string $audioCodec,
        public string $container,
        public string $filePath,
    )
    {
    }

    /**
     * Extract metadata using FFprobe.
     */
    public static function fromFile(string $filePath): static
    {
        // Example FFprobe command
        $command = 'ffprobe -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($filePath);
        $output = shell_exec($command);
        $info = json_decode($output, true);

        // Parse metadata
        $videoStream = collect($info['streams'])->firstWhere('codec_type', 'video');
        $audioStream = collect($info['streams'])->firstWhere('codec_type', 'audio');
        $format = $info['format'];

        return new static(
            width: $videoStream['width'] ?? 0,
            height: $videoStream['height'] ?? 0,
            bitrate: $format['bit_rate'] ?? 0,
            codec: $videoStream['codec_name'] ?? '',
            audioCodec: $audioStream['codec_name'] ?? '',
            container: $format['format_name'] ?? '',
            filePath: $filePath,
        );
    }
}