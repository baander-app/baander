<?php

namespace App\Services\MetadataMatching;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;

class MatchingStrategy
{
    /**
     * Find the best album match from search results
     */
    public function findBestAlbumMatch(array $results, Album $album): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        Log::debug('Finding best album match', [
            'album_id' => $album->id,
            'results_count' => count($results),
            'album_title' => $album->title
        ]);

        foreach ($results as $index => $result) {
            $score = $this->calculateAlbumMatchScore($result, $album);

            Log::debug('Album match score calculated', [
                'album_id' => $album->id,
                'result_index' => $index,
                'result_title' => $result['title'] ?? 'unknown',
                'score' => $score,
                'threshold' => 0.5 // Lowered threshold
            ]);

            if ($score > $bestScore && $score >= 0.5) { // Lowered from 0.7 to 0.5
                $bestScore = $score;
                $bestMatch = $result;
            }
        }

        if ($bestMatch) {
            Log::debug('Best match found', [
                'album_id' => $album->id,
                'match_title' => $bestMatch['title'] ?? 'unknown',
                'final_score' => $bestScore
            ]);
        } else {
            Log::debug('No suitable match found', [
                'album_id' => $album->id,
                'best_score_achieved' => $bestScore
            ]);
        }

        return $bestMatch;
    }

    /**
     * Find the best artist match from search results
     */
    public function findBestArtistMatch(array $results, Artist $artist): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $score = $this->calculateArtistMatchScore($result, $artist);

            if ($score > $bestScore && $score >= 0.6) { // Lowered from 0.8 to 0.6
                $bestScore = $score;
                $bestMatch = $result;
            }
        }

        return $bestMatch;
    }

    /**
     * Find the best song match from search results
     */
    public function findBestSongMatch(array $results, Song $song): ?array
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $score = $this->calculateSongMatchScore($result, $song);

            if ($score > $bestScore && $score >= 0.6) { // Lowered from 0.75 to 0.6
                $bestScore = $score;
                $bestMatch = $result;
            }
        }

        return $bestMatch;
    }

    /**
     * Calculate similarity between a track result and a song
     */
    public function calculateSongSimilarity(array $trackData, Song $song): float
    {
        $score = 0;
        $maxScore = 0;

        // Title similarity (weight: 50%)
        $titleSimilarity = $this->calculateStringSimilarity(
            $trackData['title'] ?? '',
            $song->title
        );
        $score += $titleSimilarity * 0.5;
        $maxScore += 0.5;

        // Track position match (weight: 20%)
        if (isset($trackData['position']) && $song->track) {
            $trackPosition = $this->normalizeTrackPosition($trackData['position']);
            if ($trackPosition === $song->track) {
                $score += 0.2;
            } elseif (abs($trackPosition - $song->track) <= 1) {
                $score += 0.1; // Close track position gets partial credit
            }
        }
        $maxScore += 0.2;

        // Duration match (weight: 20%)
        if ($song->length) {
            $trackDuration = $this->extractDuration($trackData);
            if ($trackDuration) {
                $durationDiff = abs($trackDuration - $song->length);
                $tolerance = max(10000, $song->length * 0.1); // 10 seconds or 10% tolerance

                if ($durationDiff <= $tolerance) {
                    $score += 0.2 * (1 - ($durationDiff / $tolerance));
                }
            }
        }
        $maxScore += 0.2;

        // Artist match (weight: 10%)
        if ($song->artists->isNotEmpty()) {
            $artistSimilarity = $this->calculateTrackArtistSimilarity($trackData, $song);
            $score += $artistSimilarity * 0.1;
        }
        $maxScore += 0.1;

        return $maxScore > 0 ? $score / $maxScore : 0;
    }

    private function calculateAlbumMatchScore(array $result, Album $album): float
    {
        $score = 0;
        $maxScore = 0;

        // Title similarity (weight: 60% - increased importance)
        $titleSimilarity = $this->calculateStringSimilarity(
            $result['title'] ?? '',
            $album->title
        );
        $score += $titleSimilarity * 0.6;
        $maxScore += 0.6;

        Log::debug('Album title similarity', [
            'album_id' => $album->id,
            'local_title' => $album->title,
            'result_title' => $result['title'] ?? '',
            'similarity' => $titleSimilarity
        ]);

        // Year match (weight: 20%)
        $yearScore = 0;
        if ($album->year && isset($result['date'])) {
            $resultYear = (int)substr($result['date'], 0, 4);
            if ($resultYear === $album->year) {
                $yearScore = 1.0;
            } elseif (abs($resultYear - $album->year) <= 2) { // More lenient
                $yearScore = 0.7;
            } elseif (abs($resultYear - $album->year) <= 5) {
                $yearScore = 0.4;
            }
        } elseif ($album->year && isset($result['year'])) {
            if ($result['year'] === $album->year) {
                $yearScore = 1.0;
            } elseif (abs($result['year'] - $album->year) <= 2) {
                $yearScore = 0.7;
            } elseif (abs($result['year'] - $album->year) <= 5) {
                $yearScore = 0.4;
            }
        } else {
            $yearScore = 0.5; // Neutral if no year data
        }

        $score += $yearScore * 0.2;
        $maxScore += 0.2;

        // Artist similarity (weight: 20% - reduced from 40%)
        $artistScore = 0.5; // Default neutral score
        if ($album->artists->isNotEmpty()) {
            $artistScore = $this->calculateArtistSimilarity($result, $album);
        }
        $score += $artistScore * 0.2;
        $maxScore += 0.2;

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        Log::debug('Album match score breakdown', [
            'album_id' => $album->id,
            'title_score' => $titleSimilarity,
            'year_score' => $yearScore,
            'artist_score' => $artistScore,
            'final_score' => $finalScore
        ]);

        return $finalScore;
    }

    private function calculateArtistMatchScore(array $result, Artist $artist): float
    {
        // Name similarity (weight: 100%)
        return $this->calculateStringSimilarity(
            $result['name'] ?? '',
            $artist->name
        );
    }

    private function calculateSongMatchScore(array $result, Song $song): float
    {
        $score = 0;
        $maxScore = 0;

        // Title similarity (weight: 40%)
        $titleSimilarity = $this->calculateStringSimilarity(
            $result['title'] ?? '',
            $song->title
        );
        $score += $titleSimilarity * 0.4;
        $maxScore += 0.4;

        // Length match (weight: 30%)
        if ($song->length && isset($result['length'])) {
            $lengthDiff = abs((int)$result['length'] - $song->length);
            $tolerance = max(10000, $song->length * 0.1); // 10 seconds or 10% tolerance

            if ($lengthDiff <= $tolerance) {
                $lengthScore = 1 - ($lengthDiff / $tolerance);
                $score += $lengthScore * 0.3;
            }
        }
        $maxScore += 0.3;

        // Artist similarity (weight: 20%)
        if ($song->artists->isNotEmpty()) {
            $artistScore = $this->calculateRecordingArtistSimilarity($result, $song);
            $score += $artistScore * 0.2;
        }
        $maxScore += 0.2;

        // Album/Release context (weight: 10%)
        if ($song->album && isset($result['releases'])) {
            $albumScore = $this->calculateReleaseContextSimilarity($result['releases'], $song->album);
            $score += $albumScore * 0.1;
        }
        $maxScore += 0.1;

        return $maxScore > 0 ? $score / $maxScore : 0;
    }

    private function calculateArtistSimilarity(array $result, Album $album): float
    {
        $albumArtists = $album->artists->pluck('name')->toArray();

        if (empty($albumArtists)) {
            return 0;
        }

        $resultArtists = [];

        // Extract artist names from different possible structures
        if (isset($result['artist-credit'])) {
            foreach ($result['artist-credit'] as $credit) {
                if (isset($credit['artist']['name'])) {
                    $resultArtists[] = $credit['artist']['name'];
                }
            }
        } elseif (isset($result['artists'])) {
            foreach ($result['artists'] as $artist) {
                $resultArtists[] = $artist['name'] ?? $artist;
            }
        }

        if (empty($resultArtists)) {
            return 0;
        }

        return $this->calculateArtistListSimilarity($albumArtists, $resultArtists);
    }

    private function calculateTrackArtistSimilarity(array $trackData, Song $song): float
    {
        $songArtists = $song->artists->pluck('name')->toArray();

        if (empty($songArtists)) {
            return 0;
        }

        $trackArtists = [];

        // Extract artist names from track data
        if (isset($trackData['artists'])) {
            foreach ($trackData['artists'] as $artist) {
                $trackArtists[] = $artist['name'] ?? $artist;
            }
        } elseif (isset($trackData['release_artists'])) {
            foreach ($trackData['release_artists'] as $artist) {
                $trackArtists[] = $artist['name'] ?? $artist;
            }
        }

        if (empty($trackArtists)) {
            return 0;
        }

        return $this->calculateArtistListSimilarity($songArtists, $trackArtists);
    }

    private function calculateRecordingArtistSimilarity(array $result, Song $song): float
    {
        $songArtists = $song->artists->pluck('name')->toArray();

        if (empty($songArtists)) {
            return 0;
        }

        $resultArtists = [];

        if (isset($result['artist-credit'])) {
            foreach ($result['artist-credit'] as $credit) {
                if (isset($credit['artist']['name'])) {
                    $resultArtists[] = $credit['artist']['name'];
                }
            }
        }

        if (empty($resultArtists)) {
            return 0;
        }

        return $this->calculateArtistListSimilarity($songArtists, $resultArtists);
    }

    private function calculateReleaseContextSimilarity(array $releases, Album $album): float
    {
        $bestSimilarity = 0;

        foreach ($releases as $release) {
            $releaseSimilarity = $this->calculateStringSimilarity(
                $release['title'] ?? '',
                $album->title
            );

            $bestSimilarity = max($bestSimilarity, $releaseSimilarity);
        }

        return $bestSimilarity;
    }

    private function calculateArtistListSimilarity(array $artists1, array $artists2): float
    {
        $bestSimilarity = 0;

        foreach ($artists1 as $artist1) {
            foreach ($artists2 as $artist2) {
                $similarity = $this->calculateStringSimilarity($artist1, $artist2);
                $bestSimilarity = max($bestSimilarity, $similarity);
            }
        }

        return $bestSimilarity;
    }

    private function calculateStringSimilarity(string $string1, string $string2): float
    {
        if (empty($string1) || empty($string2)) {
            return 0;
        }

        // Normalize strings for comparison
        $normalized1 = $this->normalizeString($string1);
        $normalized2 = $this->normalizeString($string2);

        // Exact match
        if ($normalized1 === $normalized2) {
            return 1.0;
        }

        // Use similar_text for fuzzy matching
        $similarity = 0;
        similar_text($normalized1, $normalized2, $similarity);

        // Also try Levenshtein distance for short strings
        if (strlen($normalized1) < 100 && strlen($normalized2) < 100) {
            $maxLen = max(strlen($normalized1), strlen($normalized2));
            if ($maxLen > 0) {
                $levenshtein = levenshtein($normalized1, $normalized2);
                $levenshteinSimilarity = (1 - ($levenshtein / $maxLen)) * 100;

                // Use the better of the two similarity measures
                $similarity = max($similarity, $levenshteinSimilarity);
            }
        }

        return min($similarity / 100, 1.0); // Ensure we don't exceed 1.0
    }

    private function normalizeString(string $string): string
    {
        // Convert to lowercase
        $normalized = strtolower($string);

        // Remove common articles and prepositions (less aggressive)
        $normalized = preg_replace('/\b(the|a|an)\b/', '', $normalized);

        // Remove special characters but keep spaces and alphanumeric
        $normalized = preg_replace('/[^\w\s]/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    private function normalizeTrackPosition(string $position): int
    {
        // Handle various track position formats like "A1", "1", "01", etc.
        $position = preg_replace('/[^0-9]/', '', $position);
        return (int) $position ?: 1;
    }

    private function extractDuration(array $trackData): ?int
    {
        if (isset($trackData['length'])) {
            return (int) $trackData['length'];
        }

        if (isset($trackData['duration'])) {
            return $this->parseDuration($trackData['duration']);
        }

        return null;
    }

    private function parseDuration(string $duration): ?int
    {
        $parts = explode(':', $duration);

        if (count($parts) === 2) {
            return (((int)$parts[0] * 60) + (int)$parts[1]) * 1000;
        } elseif (count($parts) === 3) {
            return (((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2]) * 1000;
        }

        return null;
    }
}