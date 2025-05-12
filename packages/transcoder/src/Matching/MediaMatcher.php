<?php

namespace Baander\Transcoder\Matching;

use Baander\Common\Streaming\ClientCapabilities;
use Baander\Common\Streaming\MediaMetadata;

class MediaMatcher
{
    public static function decideProcessType(ClientCapabilities $client, MediaMetadata $media): string
    {
        // Check for Direct Play: exact match of codecs, resolution, and container
        if (
            in_array($media->codec, $client->videoCodecs) &&
            in_array($media->audioCodec, $client->audioCodecs) &&
            in_array($media->container, $client->containers) &&
            $media->width <= $client->maxResolutionWidth &&
            $media->height <= $client->maxResolutionHeight &&
            $media->bitrate <= $client->maxBitrate
        ) {
            return 'DirectPlay';
        }

        // Check for Direct Stream: valid codecs but incompatible container
        if (
            in_array($media->codec, $client->videoCodecs) &&
            in_array($media->audioCodec, $client->audioCodecs)
        ) {
            return 'DirectStream';
        }

        // Fallback to full Transcoding
        return 'Transcode';
    }
}
