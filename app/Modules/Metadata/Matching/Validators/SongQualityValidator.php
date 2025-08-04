<?php

namespace App\Modules\Metadata\Matching\Validators;

use App\Models\BaseModel;
use App\Models\Song;
use Illuminate\Support\Facades\Log;

class SongQualityValidator extends BaseQualityValidator
{
    private const float MIN_QUALITY_THRESHOLD = 0.4;
    private const float HIGH_CONFIDENCE_THRESHOLD = 0.8;

    public function scoreMatch(array $metadata, BaseModel|Song $model): float
    {
        $score = 0;
        $maxScore = 0;

        Log::debug('Starting song quality score calculation', [
            'song_id' => $model->id,
            'song_title' => $model->title,
            'metadata_keys' => array_keys($metadata),
        ]);

        // Title similarity (35% weight)
        $titleScore = $this->scoreTitleSimilarity($metadata, $model);
        $score += $titleScore;
        $maxScore += 3.5;

        // Duration matching (25% weight)
        $durationScore = $this->scoreDurationMatch($metadata, $model);
        $score += $durationScore;
        $maxScore += 2.5;

        // Artist matching (25% weight)
        $artistScore = $this->scoreArtistMatch($metadata, $model);
        $score += $artistScore;
        $maxScore += 2.5;

        // Track position (10% weight)
        $positionScore = $this->scoreTrackPosition($metadata);
        $score += $positionScore;
        $maxScore += 1.0;

        // Additional metadata (5% weight)
        $additionalScore = $this->scoreAdditionalInfo($metadata);
        $score += $additionalScore;
        $maxScore += 0.5;

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        Log::debug('Song quality score calculation complete', [
            'song_id' => $model->id,
            'title_score' => $titleScore,
            'duration_score' => $durationScore,
            'artist_score' => $artistScore,
            'position_score' => $positionScore,
            'additional_score' => $additionalScore,
            'final_score' => round($finalScore, 2),
        ]);

        return round($finalScore, 2);
    }

    private function scoreTitleSimilarity(array $metadata, Song $song): float
    {
        if (empty($metadata['title'])) {
            return 0;
        }

        $similarity = $this->calculateStringSimilarity(
            $this->normalizeText($metadata['title']),
            $this->normalizeText($song->title)
        );

        return ($similarity / 100) * 3.5;
    }

    private function scoreDurationMatch(array $metadata, Song $song): float
    {
        if (!$song->length) {
            return 0;
        }

        $metadataLength = $this->extractDuration($metadata);
        if (!$metadataLength) {
            return 0;
        }

        $lengthDiff = abs($metadataLength - $song->length);
        $tolerance = max(10000, $song->length * 0.1); // 10 seconds or 10% tolerance

        if ($lengthDiff <= $tolerance) {
            $accuracy = 1 - ($lengthDiff / $tolerance);
            return $accuracy * 2.5;
        }

        return 0;
    }

    private function scoreArtistMatch(array $metadata, Song $song): float
    {
        if ($song->artists->isEmpty()) {
            return 0;
        }

        $songArtistNames = $song->artists->pluck('name')
            ->map(fn($name) => $this->normalizeText($name))
            ->toArray();

        $metadataArtists = $this->extractMetadataArtists($metadata);

        if (empty($metadataArtists)) {
            return 0;
        }

        $maxSimilarity = 0;
        foreach ($metadataArtists as $metadataArtist) {
            foreach ($songArtistNames as $songArtist) {
                $similarity = $this->calculateStringSimilarity($metadataArtist, $songArtist);
                $maxSimilarity = max($maxSimilarity, $similarity);
            }
        }

        return ($maxSimilarity / 100) * 2.5;
    }

    private function extractMetadataArtists(array $metadata): array
    {
        $artists = [];

        if (isset($metadata['artist-credit']) && is_array($metadata['artist-credit'])) {
            foreach ($metadata['artist-credit'] as $credit) {
                if (isset($credit['artist']['name'])) {
                    $artists[] = $this->normalizeText($credit['artist']['name']);
                }
            }
        }

        if (!empty($metadata['artist'])) {
            $artists[] = $this->normalizeText($metadata['artist']);
        }

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

    private function extractDuration(array $metadata): ?int
    {
        // MusicBrainz uses milliseconds
        if (isset($metadata['length']) && is_numeric($metadata['length'])) {
            return (int) $metadata['length'];
        }

        if (isset($metadata['duration']) && is_numeric($metadata['duration'])) {
            return (int) $metadata['duration'];
        }

        // Convert seconds to milliseconds
        if (isset($metadata['track-length']) && is_numeric($metadata['track-length'])) {
            return (int) $metadata['track-length'] * 1000;
        }

        return null;
    }

    private function scoreTrackPosition(array $metadata): float
    {
        if ($this->hasAnyField($metadata, ['position', 'track', 'track-number'])) {
            return 1.0;
        }
        return 0;
    }

    private function scoreAdditionalInfo(array $metadata): float
    {
        $score = 0;

        if ($this->hasAnyField($metadata, ['genres', 'tags'])) {
            $score += 0.3;
        }

        if (!empty($metadata['recording'])) {
            $score += 0.2;
        }

        return min($score, 0.5);
    }

    public function isValidMatch(array $metadata, float $qualityScore): bool
    {
        return $qualityScore >= self::MIN_QUALITY_THRESHOLD && !empty($metadata['title']);
    }

    public function isHighConfidenceMatch(array $metadata, BaseModel|Song $model, float $qualityScore): bool
    {
        if ($qualityScore < self::HIGH_CONFIDENCE_THRESHOLD) {
            return false;
        }

        // High confidence requires very high title similarity
        if (!empty($metadata['title'])) {
            $titleSimilarity = $this->calculateStringSimilarity(
                $this->normalizeText($metadata['title']),
                $this->normalizeText($model->title)
            );
            if ($titleSimilarity < 85) {
                return false;
            }
        }

        // Need at least one additional strong match
        $strongMatches = 0;

        // Duration match
        if ($this->scoreDurationMatch($metadata, $model) > 2.0) {
            $strongMatches++;
        }

        // Artist match
        if ($this->scoreArtistMatch($metadata, $model) > 2.0) {
            $strongMatches++;
        }

        return $strongMatches >= 1;
    }
}