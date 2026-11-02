<?php

declare(strict_types=1);

namespace App\Media\Application\DTO;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class DeliverySource
{
    public function __construct(
        public ?string $container,
        public ?string $videoCodec,
        public ?int $videoStreamIndex,
        public ?string $audioCodec,
        public ?int $audioStreamIndex,
        public ?int $audioChannels,
        public ?int $width = null,
        public ?int $height = null,
        public bool $video = true,
        public ?int $bitDepth = null,
        public ?bool $hdr = null,
        public ?string $pixelFormat = null,
        public bool $audioSelectionRequiresRemux = false,
    ) {
    }
}
