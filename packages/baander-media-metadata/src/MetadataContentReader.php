<?php

declare(strict_types=1);

namespace Baander\MediaMetadata;

use Baander\MediaMetadata\Model\ExtractedMetadata;
use Baander\MediaMetadata\Reader\FlacReader;
use Baander\MediaMetadata\Reader\Id3Reader;
use Baander\MediaMetadata\Reader\OggReader;
use Baander\MediaMetadata\Reader\WavReader;
use Baander\MediaMetadata\Reader\Mp4Reader;
use Baander\MediaMetadata\Reader\AsfReader;
use Baander\MediaMetadata\Reader\AiffReader;
use Baander\MediaMetadata\Reader\AacReader;

final class MetadataContentReader
{
    public function __construct(
        private readonly FormatDetector $formatDetector,
        private readonly Id3Reader $id3Reader,
        private readonly FlacReader $flacReader,
        private readonly OggReader $oggReader,
        private readonly WavReader $wavReader,
        private readonly Mp4Reader $mp4Reader,
        private readonly AsfReader $asfReader,
        private readonly AiffReader $aiffReader,
        private readonly AacReader $aacReader,
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
        return match (strtolower($format)) {
            'mp3' => $this->id3Reader->read($path),
            'm4a', 'mp4', 'mp4a', 'm4b' => $this->mp4Reader->read($path),
            'wma', 'asf' => $this->asfReader->read($path),
            'aif', 'aiff', 'aifc' => $this->aiffReader->read($path),
            'aac' => $this->aacReader->read($path),
            'flac' => $this->flacReader->read($path),
            'ogg', 'oga', 'opus' => $this->oggReader->read($path),
            'wav', 'wave' => $this->wavReader->read($path),
            default => null,
        };
    }
}
