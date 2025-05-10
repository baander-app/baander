<?php

namespace Baander\Transcoder\Services;

use Amp\Future;
use Baander\Transcoder\Probe\Probe;
use Baander\Transcoder\Probe\Models\FFprobeMetadata;

class MediaQualityService
{

    public function __construct(private readonly Probe $probe)
    {
    }

    /**
     * Determines the available qualities for transcoding based on the input file's metadata.
     *
     * @param string $filePath The path to the input media file.
     * @return Future<string[]> A list of available qualities based on the source (e.g., ['2160p', '1080p', '720p']).
     */
    public function getAvailableQualities(string $filePath): Future
    {
        return \Amp\async(function () use ($filePath) {
            // Analyze the source file
            /** @var FFprobeMetadata $metadata */
            $metadata = yield $this->probe->analyze($filePath);

            // Parse the video streams
            $videoStream = $this->getHighestQualityVideoStream($metadata);

            if ($videoStream === null) {
                throw new \RuntimeException('No valid video stream found in the media file.');
            }

            // Extract source video resolution
            $sourceWidth = $videoStream->width;
            $sourceHeight = $videoStream->height;

            if (!$sourceWidth || !$sourceHeight) {
                throw new \RuntimeException('Invalid video resolution found in the media file.');
            }

            // Define target qualities
            $qualities = [
                '2160p' => [3840, 2160], // 4K
                '1440p' => [2560, 1440], // 2K
                '1080p' => [1920, 1080], // Full HD
                '720p'  => [1280, 720],  // HD
                '480p'  => [854, 480],   // SD
                '360p'  => [640, 360],   // Low Quality
            ];

            // Filter qualities that exceed the source quality
            return array_keys(array_filter($qualities, function ($resolution) use ($sourceWidth, $sourceHeight) {
                return $resolution[0] <= $sourceWidth && $resolution[1] <= $sourceHeight;
            }));
        });
    }

    /**
     * Retrieves the highest-quality video stream from metadata.
     *
     * @param FFprobeMetadata $metadata The full metadata from FFprobe.
     * @return \Baander\Transcoder\Probe\Models\Stream|null The highest-quality video stream, or null if none found.
     */
    private function getHighestQualityVideoStream(FFprobeMetadata $metadata): ?\Baander\Transcoder\Probe\Models\Stream
    {
        $videoStreams = array_filter(
            $metadata->streams,
            fn($stream) => $stream->codecType === 'video' && $stream->width !== null && $stream->height !== null
        );

        // Return the stream with the highest resolution
        return !empty($videoStreams)
            ? max($videoStreams, fn($a, $b) => ($a->width * $a->height) <=> ($b->width * $b->height))
            : null;
    }
}