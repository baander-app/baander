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

        return $format === null ? null : $this->readAs($path, $format);
    }

    /**
     * The tags of a file whose format was already detected, or null when no reader handles the format.
     *
     * @param string $format a format FormatDetector names, such as 'flac'
     */
    public function readAs(string $path, string $format): ?ExtractedMetadata
    {
        return match ($format) {
            'mp3', 'm4a' => $this->id3Reader->read($path),
            'flac' => $this->flacReader->read($path),
            'ogg', 'opus' => $this->oggReader->read($path),
            'wav' => $this->wavReader->read($path),
            default => null,
        };
    }
}
