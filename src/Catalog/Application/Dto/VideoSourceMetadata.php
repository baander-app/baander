<?php

declare(strict_types=1);

namespace App\Catalog\Application\Dto;

use App\Shared\Domain\Model\Uuid;

final readonly class VideoSourceMetadata
{
    /**
     * @param list<array<string, mixed>> $audioStreams
     * @param array<string, mixed>|null $colorSpace
     */
    public function __construct(
        public Uuid $videoId,
        public string $publicId,
        public string $filePath,
        public string $filename,
        public int $size,
        public string $mimeType,
        public string $hash,
        public ?string $containerFormat,
        public ?string $videoCodec,
        public ?int $videoStreamIndex,
        public array $audioStreams,
        public ?int $width,
        public ?int $height,
        public ?float $framerate,
        public ?float $duration,
        public ?float $containerStartTime = null,
        public ?float $videoStartTime = null,
        public ?string $videoTimeBase = null,
        public ?string $pixFmt = null,
        public ?array $colorSpace = null,
        public ?string $colorRange = null,
        public ?float $sourceTimestampOrigin = null,
    ) {
    }
}
