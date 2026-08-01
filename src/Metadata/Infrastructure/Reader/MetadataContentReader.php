<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use App\Catalog\Application\Port\MetadataContentReaderPortInterface;
use App\Metadata\Domain\Model\ExtractedMetadata;

final class MetadataContentReader implements MetadataContentReaderPortInterface
{
    public function __construct(
        private readonly FormatDetector $formatDetector,
        private readonly Id3Reader $id3Reader,
        private readonly FlacReader $flacReader,
        private readonly OggReader $oggReader,
        private readonly WavReader $wavReader,
    ) {
    }

    public function readMetadata(string $path): ?ExtractedMetadata
    {
        $format = $this->formatDetector->detect($path);

        if ($format === null) {
            return null;
        }

        return match ($format) {
            'mp3', 'm4a' => $this->id3Reader->read($path),
            'flac' => $this->flacReader->read($path),
            'ogg', 'opus' => $this->oggReader->read($path),
            'wav' => $this->wavReader->read($path),
            default => null,
        };
    }
}
