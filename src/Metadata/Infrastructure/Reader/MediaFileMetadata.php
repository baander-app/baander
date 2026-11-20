<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Reader;

use App\Metadata\Application\Exception\UnreadableMediaFileException;
use App\Metadata\Application\Port\MediaFileMetadataInterface;
use App\Metadata\Domain\Model\MetadataMatch;
use App\Metadata\Infrastructure\Matching\MatchingStrategy;
use Baander\MediaMetadata\Model\ExtractedMetadata;
use Baander\MediaMetadata\FormatDetector;

/**
 * Reads a file's tags with the readers the library scan uses, so every format the scan reads
 * can be extracted and matched.
 */
final readonly class MediaFileMetadata implements MediaFileMetadataInterface
{
    public function __construct(
        private FormatDetector $formatDetector,
        private MetadataContentReaderAdapter $reader,
        private MatchingStrategy $matchingStrategy,
    ) {
    }

    #[\Override]
    public function extract(string $path): array
    {
        [$format, $metadata] = $this->read($path);

        return ['format' => $format, 'metadata' => self::tags($metadata)];
    }

    #[\Override]
    public function match(string $path, array $candidates): array
    {
        [, $metadata] = $this->read($path);

        $matches = $this->matchingStrategy->match($metadata, $candidates);

        return array_map(self::matchData(...), $matches) |> array_values(...);
    }

    /**
     * @return array{string, ExtractedMetadata}
     *
     * @throws UnreadableMediaFileException
     */
    private function read(string $path): array
    {
        if (!file_exists($path)) {
            throw UnreadableMediaFileException::missing();
        }

        $format = $this->formatDetector->detect($path);
        $metadata = $format === null ? null : $this->reader->readAs($path, $format);
        if ($format === null || $metadata === null) {
            throw UnreadableMediaFileException::unsupportedFormat();
        }

        return [$format, $metadata];
    }

    /** @return array<string, mixed> */
    private static function tags(ExtractedMetadata $metadata): array
    {
        return [
            'title' => $metadata->getTitle(),
            'artist' => $metadata->getArtist(),
            'album' => $metadata->getAlbum(),
            'albumArtist' => $metadata->getAlbumArtist(),
            'trackNumber' => $metadata->getTrackNumber(),
            'discNumber' => $metadata->getDiscNumber(),
            'year' => $metadata->getYear(),
            'genre' => $metadata->getGenre(),
            'composer' => $metadata->getComposer(),
            'bpm' => $metadata->getBpm(),
            'duration' => $metadata->getDuration(),
            'bitrate' => $metadata->getBitrate(),
            'mbid' => $metadata->getMbid(),
            'mbAlbumId' => $metadata->getMbAlbumId(),
            'mbArtistId' => $metadata->getMbArtistId(),
        ];
    }

    /** @return array{source: mixed, sourceId: mixed, confidence: float, data: array<string, mixed>} */
    private static function matchData(MetadataMatch $match): array
    {
        $candidate = $match->getCandidate();

        return [
            'source' => $candidate['source'] ?? null,
            'sourceId' => $candidate['sourceId'] ?? null,
            'confidence' => $match->getConfidence(),
            'data' => [
                'title' => $candidate['title'] ?? null,
                'artist' => $candidate['artist'] ?? null,
                'album' => $candidate['album'] ?? null,
                'year' => $candidate['year'] ?? null,
                'mbid' => $candidate['mbid'] ?? null,
            ],
        ];
    }
}
