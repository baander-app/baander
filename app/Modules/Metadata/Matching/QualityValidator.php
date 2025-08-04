<?php

namespace App\Modules\Metadata\Matching;

use App\Models\{Album, Artist, Song};
use App\Modules\Metadata\Matching\Validators\{
    ArtistQualityValidator,
    AlbumQualityValidator,
    SongQualityValidator
};

class QualityValidator
{
    public function __construct(
        private readonly ArtistQualityValidator $artistValidator,
        private readonly AlbumQualityValidator $albumValidator,
        private readonly SongQualityValidator $songValidator,
    ) {}

    public function scoreArtistMatch(array $metadata, Artist $artist): float
    {
        return $this->artistValidator->scoreMatch($metadata, $artist);
    }

    public function scoreAlbumMatch(array $metadata, Album $album): float
    {
        return $this->albumValidator->scoreMatch($metadata, $album);
    }

    public function scoreSongMatch(array $metadata, Song $song): float
    {
        return $this->songValidator->scoreMatch($metadata, $song);
    }

    public function isValidMatch(array $metadata, float $qualityScore): bool
    {
        if (isset($metadata['title'])) {
            return $this->albumValidator->isValidMatch($metadata, $qualityScore) ||
                $this->songValidator->isValidMatch($metadata, $qualityScore);
        }

        if (isset($metadata['name'])) {
            return $this->artistValidator->isValidMatch($metadata, $qualityScore);
        }

        return false;
    }

    public function isHighConfidenceArtistMatch(array $metadata, Artist $artist, float $qualityScore): bool
    {
        return $this->artistValidator->isHighConfidenceMatch($metadata, $artist, $qualityScore);
    }

    public function isHighConfidenceSongMatch(array $metadata, Song $song, float $qualityScore): bool
    {
        return $this->songValidator->isHighConfidenceMatch($metadata, $song, $qualityScore);
    }
}