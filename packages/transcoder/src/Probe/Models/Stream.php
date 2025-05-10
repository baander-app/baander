<?php

namespace Baander\Transcoder\Probe\Models;

/**
 * Represents metadata for a single stream in the media file.
 */
final class Stream
{
    public readonly int $index;
    public readonly string $codecName;
    public readonly string $codecType;
    public readonly ?int $width;
    public readonly ?int $height;
    public readonly ?string $frameRate;
    public readonly ?int $channels;
    public readonly ?string $channelLayout;
    public readonly ?float $duration;
    public readonly ?array $tags;

    public function __construct(
        int $index,
        string $codecName,
        string $codecType,
        ?int $width = null,
        ?int $height = null,
        ?string $frameRate = null,
        ?int $channels = null,
        ?string $channelLayout = null,
        ?float $duration = null,
        ?array $tags = null
    ) {
        $this->index = $index;
        $this->codecName = $codecName;
        $this->codecType = $codecType;
        $this->width = $width;
        $this->height = $height;
        $this->frameRate = $frameRate;
        $this->channels = $channels;
        $this->channelLayout = $channelLayout;
        $this->duration = $duration;
        $this->tags = $tags;
    }
}