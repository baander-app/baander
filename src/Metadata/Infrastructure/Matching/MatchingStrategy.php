<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Matching;

use App\Metadata\Domain\Model\ExtractedMetadata;
use App\Metadata\Domain\Model\MetadataMatch;
use App\Metadata\Infrastructure\Matching\Validator\AlbumValidator;
use App\Metadata\Infrastructure\Matching\Validator\ArtistValidator;
use App\Metadata\Infrastructure\Matching\Validator\SongValidator;

/**
 * Orchestrates matching of extracted file metadata against a set of external candidates.
 *
 * Each candidate is scored independently using three weighted validators:
 *   - Artist score  (weight 0.3)
 *   - Album score   (weight 0.3)
 *   - Song score    (weight 0.4)
 *
 * Results are returned sorted by descending confidence, filtered to a
 * minimum threshold of 0.2.
 */
final class MatchingStrategy
{
    private const MINIMUM_CONFIDENCE = 0.2;
    private const WEIGHT_ARTIST = 0.3;
    private const WEIGHT_ALBUM = 0.3;
    private const WEIGHT_SONG = 0.4;

    public function __construct(
        private readonly ArtistValidator $artistValidator,
        private readonly AlbumValidator $albumValidator,
        private readonly SongValidator $songValidator,
    ) {
    }

    /**
     * Match extracted metadata against a list of external candidates.
     *
     * @param ExtractedMetadata $extracted          Metadata extracted from the audio file
     * @param array<int, array{title?: string, artist?: string, album?: string, trackNumber?: int|null}> $externalCandidates
     *                                              Each candidate must contain at least a 'title' key
     *
     * @return MetadataMatch[] Sorted by confidence descending; only candidates above the minimum threshold
     */
    public function match(ExtractedMetadata $extracted, array $externalCandidates): array
    {
        $matches = [];

        foreach ($externalCandidates as $candidate) {
            $candidateTitle = $candidate['title'] ?? '';
            $candidateArtist = $candidate['artist'] ?? '';
            $candidateAlbum = $candidate['album'] ?? '';

            $artistScore = $this->artistValidator->validate(
                $extracted->getArtist() ?? '',
                $candidateArtist,
            );

            $albumScore = $this->albumValidator->validate(
                $extracted->getAlbum() ?? '',
                $candidateAlbum,
            );

            $songScore = $this->songValidator->validate(
                $extracted->getTitle() ?? '',
                $candidateTitle,
            );

            $confidence = ($artistScore * self::WEIGHT_ARTIST)
                + ($albumScore * self::WEIGHT_ALBUM)
                + ($songScore * self::WEIGHT_SONG);

            if ($confidence <= self::MINIMUM_CONFIDENCE) {
                continue;
            }

            $matches[] = new MetadataMatch(
                candidate: $candidate,
                confidence: $confidence,
                artistScore: $artistScore,
                albumScore: $albumScore,
                songScore: $songScore,
            );
        }

        usort($matches, static fn (MetadataMatch $a, MetadataMatch $b): int => $b->confidence <=> $a->confidence);

        return $matches;
    }
}
