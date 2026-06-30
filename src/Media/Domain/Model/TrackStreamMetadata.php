<?php

declare(strict_types=1);

namespace App\Media\Domain\Model;

final readonly class TrackStreamMetadata
{
    public function __construct(
        public string $publicId,
        public string $filename,
        public string $filePath,
        public string $mimeType,
        public int $size,
        public ?string $codec,
        public ?int $bitrate,
        public ?int $sampleRate,
        public ?int $channels,
        public ?float $length,
    ) {
    }
}
