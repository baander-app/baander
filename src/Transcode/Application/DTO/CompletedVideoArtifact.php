<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class CompletedVideoArtifact
{
    public function __construct(
        public string $url,
        public string $container,
        public string $videoCodec,
        public ?string $audioCodec,
        public int $width,
        public int $height,
        public ?int $audioChannels,
        public int $sourceVideoStreamIndex,
        public ?int $sourceAudioStreamIndex,
        public int $bitDepth,
        public bool $hdr,
        public string $transport,
        public string $pixelFormat,
    ) {
    }
}
