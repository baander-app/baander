<?php

namespace App\Modules\Metadata\Matching\Validators;

use App\Models\Album;
use App\Models\BaseModel;
use Illuminate\Support\Facades\Log;

class AlbumQualityValidator extends BaseQualityValidator
{
    private const float MIN_QUALITY_THRESHOLD = 0.4;
    private const float HIGH_CONFIDENCE_THRESHOLD = 0.7;

    public function scoreMatch(array $metadata, Album|BaseModel $model): float
    {
        $score = 0;
        $maxScore = 0;

        Log::debug('Starting album quality score calculation', [
            'album_id' => $model->id,
            'album_title' => $model->title,
            'metadata_keys' => array_keys($metadata),
        ]);

        // Title similarity (30% weight)
        $titleScore = $this->scoreTitleSimilarity($metadata, $model);
        $score += $titleScore;
        $maxScore += 3.0;

        // Artist information (25% weight)
        $artistScore = $this->scoreArtistInfo($metadata, $model);
        $score += $artistScore;
        $maxScore += 2.5;

        // Track listing (20% weight)
        $tracksScore = $this->scoreTrackListing($metadata);
        $score += $tracksScore;
        $maxScore += 2.0;

        // Release information (15% weight)
        $releaseScore = $this->scoreReleaseInfo($metadata);
        $score += $releaseScore;
        $maxScore += 1.5;

        // Additional metadata (10% weight)
        $additionalScore = $this->scoreAdditionalInfo($metadata);
        $score += $additionalScore;
        $maxScore += 1.0;

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        Log::debug('Album quality score calculation complete', [
            'album_id' => $model->id,
            'title_score' => $titleScore,
            'artist_score' => $artistScore,
            'tracks_score' => $tracksScore,
            'release_score' => $releaseScore,
            'additional_score' => $additionalScore,
            'final_score' => round($finalScore, 2),
        ]);

        return round($finalScore, 2);
    }

    private function scoreTitleSimilarity(array $metadata, Album $album): float
    {
        if (empty($metadata['title'])) {
            return 0;
        }

        $similarity = $this->calculateStringSimilarity(
            $this->normalizeText($metadata['title']),
            $this->normalizeText($album->title)
        );

        return ($similarity / 100) * 3.0;
    }

    private function scoreArtistInfo(array $metadata, Album $album): float
    {
        if ($album->artists->isEmpty()) {
            return 0;
        }

        $albumArtistNames = $album->artists->pluck('name')
            ->map(fn($name) => $this->normalizeText($name))
            ->toArray();

        // Check various metadata artist fields
        $metadataArtists = $this->extractMetadataArtists($metadata);

        if (empty($metadataArtists)) {
            return 0;
        }

        $maxSimilarity = 0;
        foreach ($metadataArtists as $metadataArtist) {
            foreach ($albumArtistNames as $albumArtist) {
                $similarity = $this->calculateStringSimilarity($metadataArtist, $albumArtist);
                $maxSimilarity = max($maxSimilarity, $similarity);
            }
        }

        return ($maxSimilarity / 100) * 2.5;
    }

    private function extractMetadataArtists(array $metadata): array
    {
        $artists = [];

        // MusicBrainz artist-credit format
        if (isset($metadata['artist-credit']) && is_array($metadata['artist-credit'])) {
            foreach ($metadata['artist-credit'] as $credit) {
                if (isset($credit['artist']['name'])) {
                    $artists[] = $this->normalizeText($credit['artist']['name']);
                }
            }
        }

        // Simple artist field
        if (!empty($metadata['artist'])) {
            $artists[] = $this->normalizeText($metadata['artist']);
        }

        // Artists array
        if (isset($metadata['artists']) && is_array($metadata['artists'])) {
            foreach ($metadata['artists'] as $artist) {
                $name = is_array($artist) ? ($artist['name'] ?? '') : $artist;
                if ($name) {
                    $artists[] = $this->normalizeText($name);
                }
            }
        }

        return array_unique($artists);
    }

    private function scoreTrackListing(array $metadata): float
    {
        $score = 0;

        // Check for track listing in various formats
        if (isset($metadata['media']) && is_array($metadata['media'])) {
            foreach ($metadata['media'] as $medium) {
                if (isset($medium['tracks']) && is_array($medium['tracks'])) {
                    $trackCount = count($medium['tracks']);
                    $score += min(1.0, $trackCount * 0.05); // More tracks = better score
                }
            }
        }

        if (isset($metadata['tracklist']) && is_array($metadata['tracklist'])) {
            $score += 1.0;
        }

        if (isset($metadata['tracks']) && is_array($metadata['tracks'])) {
            $score += 1.0;
        }

        return min($score, 2.0);
    }

    private function scoreReleaseInfo(array $metadata): float
    {
        $score = 0;

        // Release date
        if ($this->hasAnyField($metadata, ['date', 'year', 'release-date', 'first-release-date'])) {
            $score += 0.6;
        }

        // Release country/area
        if ($this->hasAnyField($metadata, ['country', 'release-events'])) {
            $score += 0.3;
        }

        // Barcode/catalog info
        if ($this->hasAnyField($metadata, ['barcode', 'label-info'])) {
            $score += 0.3;
        }

        // Status info
        if (!empty($metadata['status'])) {
            $score += 0.3;
        }

        return min($score, 1.5);
    }

    private function scoreAdditionalInfo(array $metadata): float
    {
        $score = 0;

        // Genre/style information
        if ($this->hasAnyField($metadata, ['genres', 'styles', 'tags'])) {
            $score += 0.4;
        }

        // Cover art
        if ($this->hasAnyField($metadata, ['cover-art-archive', 'images', 'artwork'])) {
            $score += 0.3;
        }

        // Additional identifiers
        if ($this->hasAnyField($metadata, ['mbid', 'discogs-id', 'asin'])) {
            $score += 0.3;
        }

        return min($score, 1.0);
    }

    public function isValidMatch(array $metadata, float $qualityScore): bool
    {
        return $qualityScore >= self::MIN_QUALITY_THRESHOLD && !empty($metadata['title']);
    }

    public function isHighConfidenceMatch(array $metadata, Album|BaseModel $model, float $qualityScore): bool
    {
        if ($qualityScore < self::HIGH_CONFIDENCE_THRESHOLD) {
            return false;
        }

        // High confidence requires good title similarity
        if (!empty($metadata['title'])) {
            $titleSimilarity = $this->calculateStringSimilarity(
                $this->normalizeText($metadata['title']),
                $this->normalizeText($model->title)
            );
            if ($titleSimilarity < 80) {
                return false;
            }
        }

        // Must have track listing or strong artist match
        return $this->hasTrackListing($metadata) || $this->hasStrongArtistMatch($metadata, $model);
    }

    private function hasTrackListing(array $metadata): bool
    {
        return isset($metadata['media']) ||
            isset($metadata['tracklist']) ||
            isset($metadata['tracks']);
    }

    private function hasStrongArtistMatch(array $metadata, Album $album): bool
    {
        if ($album->artists->isEmpty()) {
            return false;
        }

        $artistScore = $this->scoreArtistInfo($metadata, $album);
        return $artistScore > 2.0; // Strong artist match threshold
    }
}