<?php

namespace Baander\Transcoder\Filter;

class FilterManager
{
    /**
     * Generate FFmpeg video filters for scaling.
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    public function getVideoFilter(int $width, int $height): string
    {
        return sprintf('scale=%d:%d', $width, $height);
    }

    /**
     * Generate FFmpeg arguments for a Video Profile.
     *
     * @param int $bitrate
     * @return array<string> FFmpeg codec arguments.
     */
    public function getVideoCodecArgs(int $bitrate): array
    {
        return [
            '-c:v libx264',
            '-b:v ' . intval($bitrate) . 'k',
            '-preset medium',
        ];
    }

    /**
     * Generate audio codec and bitrate settings.
     *
     * @param int $bitrate
     * @return array<string>
     */
    public function getAudioCodecArgs(int $bitrate): array
    {
        return [
            '-c:a aac',
            '-b:a ' . intval($bitrate) . 'k',
        ];
    }
}