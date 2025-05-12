<?php

namespace Baander\Transcoder\Probe\Models;

final class FFprobeMapper
{
    /**
     * Maps raw FFprobe output into structured FFprobeMetadata.
     *
     * @param array $output FFprobe JSON output as an associative array.
     * @return FFprobeMetadata
     */
    public static function map(array $output): FFprobeMetadata
    {
        // Map and collect streams
        $streams = array_map(fn($stream) => self::mapStream($stream), $output['streams'] ?? []);

        // Map format (if present)
        $format = isset($output['format']) ? self::mapFormat($output['format']) : null;

        return new FFprobeMetadata($streams, $format);
    }

    /**
     * Maps a single FFprobe stream entry to the Stream model.
     *
     * @param array $stream
     * @return Stream
     */
    private static function mapStream(array $stream): Stream
    {
        return new Stream(
            index: $stream['index'],
            codecName: $stream['codec_name'] ?? '',
            codecType: $stream['codec_type'] ?? '',
            width: $stream['width'] ?? null,
            height: $stream['height'] ?? null,
            frameRate: $stream['r_frame_rate'] ?? null,
            channels: $stream['channels'] ?? null,
            channelLayout: $stream['channel_layout'] ?? null,
            duration: isset($stream['duration']) ? (float)$stream['duration'] : null,
            tags: isset($stream['tags']) ? self::mapTags($stream['tags']) : null
        );
    }

    /**
     * Maps FFprobe format fields into the Format model.
     *
     * @param array $format
     * @return Format
     */
    private static function mapFormat(array $format): Format
    {
        return new Format(
            filename: $format['filename'],
            size: isset($format['size']) ? (int)$format['size'] : null,
            duration: isset($format['duration']) ? (float)$format['duration'] : null,
            bitRate: isset($format['bit_rate']) ? (int)$format['bit_rate'] : null,
            streamCount: isset($format['nb_streams']) ? (int)$format['nb_streams'] : null,
            tags: isset($format['tags']) ? self::mapTags($format['tags']) : null
        );
    }

    /**
     * Maps raw tags (key-value pairs) into an array of MetadataTag.
     *
     * @param array $tags
     * @return MetadataTag[]
     */
    private static function mapTags(array $tags): array
    {
        $tagObjects = [];

        foreach ($tags as $key => $value) {
            $tagObjects[] = new MetadataTag($key, $value);
        }

        return $tagObjects;
    }
}