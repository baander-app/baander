<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Transcode\Application\Port\AudioRenditionFormat;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class AudioRenditionInput
{
    public function __construct(
        public string $trackKey,
        public string $sourceSignature,
        public string $sourcePath,
        public AudioRenditionFormat $format,
        public int $bitrate,
        public string $outputDirectory,
    ) {
        if ($trackKey === '' || $sourceSignature === '' || basename($sourceSignature) !== $sourceSignature
            || !str_starts_with($sourcePath, '/') || !str_starts_with($outputDirectory, '/') || $bitrate < 1
        ) {
            throw new \InvalidArgumentException('Audio rendition requires a canonical source and absolute paths.');
        }
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->trackKey, $this->sourceSignature, $this->format->value, $this->bitrate], JSON_THROW_ON_ERROR));
    }
}
