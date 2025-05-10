<?php

namespace Baander\Common\Streaming;

class FFmpegCommandBuilder
{
    public static function createTranscodeCommand(MediaMetadata $media, ClientCapabilities $client, string $outputPath): string
    {
        $videoScale = "{$client->maxResolutionWidth}:{$client->maxResolutionHeight}";
        $bitrate = "{$client->maxBitrate}k";

        return "ffmpeg -i {$media->filePath} -vf scale={$videoScale} -b:v {$bitrate} -c:v libx264 -b:a 128k -c:a aac {$outputPath}";
    }

    public static function createRemuxCommand(MediaMetadata $media, string $outputPath): string
    {
        return "ffmpeg -i {$media->filePath} -c copy {$outputPath}";
    }
}