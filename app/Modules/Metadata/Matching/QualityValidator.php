<?php

namespace App\Modules\Metadata\Matching;

use App\Models\{Album, Artist, Song};
use Illuminate\Support\Facades\Log;

class QualityValidator
{
    /**
     * Score the quality of an album match
     */
    public function scoreAlbumMatch(array $metadata, Album $album): float
    {
        $score = 0;
        $maxScore = 0;

        Log::debug('Starting quality score calculation', [
            'album_id'        => $album->id,
            'metadata_keys'   => array_keys($metadata),
            'metadata_sample' => array_slice($metadata, 0, 5, true),
        ]);

        // Essential fields (higher weight)
        if (!empty($metadata['title'])) {
            $score += 2;
            Log::debug('Title present - added 2 points');
        }
        $maxScore += 2;

        // Artist information (high weight)
        if ($this->hasArtistInfo($metadata)) {
            $score += 2;
            Log::debug('Artist info present - added 2 points');
        }
        $maxScore += 2;

        // Track listing (high value)
        if ($this->hasTrackListing($metadata)) {
            $score += 1.5;
            Log::debug('Track listing present - added 1.5 points');
        }
        $maxScore += 1.5;

        // Release date (medium value)
        if ($this->hasReleaseDate($metadata)) {
            $score += 1;
            Log::debug('Release date present - added 1 point');
        }
        $maxScore += 1;

        // Genre information (medium value)
        if ($this->hasGenreInfo($metadata)) {
            $score += 1;
            Log::debug('Genre info present - added 1 point');
        }
        $maxScore += 1;

        // Album artwork/images (nice to have)
        if ($this->hasArtwork($metadata)) {
            $score += 0.5;
            Log::debug('Artwork present - added 0.5 points');
        }
        $maxScore += 0.5;

        // Additional metadata completeness bonus
        if ($this->isCompleteMetadata($metadata)) {
            $score += 1;
            Log::debug('Complete metadata bonus - added 1 point');
        }
        $maxScore += 1;

        // Title similarity bonus (if we can compare)
        if (!empty($metadata['title']) && !empty($album->title)) {
            $similarity = $this->calculateStringSimilarity(
                mb_strtolower($metadata['title']),
                mb_strtolower($album->title),
            );
            $titleBonus = round(($similarity / 100) * 0.5, 2); // Max 0.5 bonus
            $score += $titleBonus;
            $maxScore += 0.5;
            Log::debug('Title similarity bonus', [
                'similarity' => $similarity,
                'bonus'      => $titleBonus,
            ]);
        } else {
            $maxScore += 0.5;
        }

        // Round both score and maxScore for consistency
        $score = round($score, 2);
        $maxScore = round($maxScore, 2);

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        Log::debug('Quality score calculation complete', [
            'album_id'        => $album->id,
            'raw_score'       => $score,
            'max_score'       => $maxScore,
            'final_score'     => $finalScore,
            'score_breakdown' => [
                'has_title'   => !empty($metadata['title']),
                'has_artist'  => $this->hasArtistInfo($metadata),
                'has_tracks'  => $this->hasTrackListing($metadata),
                'has_date'    => $this->hasReleaseDate($metadata),
                'has_genre'   => $this->hasGenreInfo($metadata),
                'has_artwork' => $this->hasArtwork($metadata),
                'is_complete' => $this->isCompleteMetadata($metadata),
            ],
        ]);

        return round($finalScore, 2);
    }

    /**
     * Score the quality of an artist match - now uses actual artist data for comparison
     */
    public function scoreArtistMatch(array $metadata, Artist $artist): float
    {
        $score = 0;
        $maxScore = 0;

        Log::debug('Starting artist quality score calculation', [
            'artist_id'       => $artist->id,
            'artist_name'     => $artist->name,
            'metadata_keys'   => array_keys($metadata),
        ]);

        // Name similarity (essential field - high weight)
        if (!empty($metadata['name'])) {
            $nameSimilarity = $this->calculateStringSimilarity(
                mb_strtolower($metadata['name']),
                mb_strtolower($artist->name)
            );
            $nameScore = ($nameSimilarity / 100) * 3; // Max 3 points for name
            $score += $nameScore;

            Log::debug('Artist name similarity', [
                'metadata_name' => $metadata['name'],
                'artist_name' => $artist->name,
                'similarity' => $nameSimilarity,
                'score_added' => $nameScore,
            ]);
        }
        $maxScore += 3;

        // Discography information (medium weight)
        if ($this->hasDiscography($metadata)) {
            $score += 1.5;
            Log::debug('Discography present - added 1.5 points');
        }
        $maxScore += 1.5;

        // Artist details (medium weight)
        if ($this->hasArtistDetails($metadata)) {
            $score += 1;
            Log::debug('Artist details present - added 1 point');
        }
        $maxScore += 1;

        // Additional bonus for comprehensive metadata
        if ($this->isCompleteArtistMetadata($metadata)) {
            $score += 0.5;
            Log::debug('Complete artist metadata bonus - added 0.5 points');
        }
        $maxScore += 0.5;

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        Log::debug('Artist quality score calculation complete', [
            'artist_id'   => $artist->id,
            'raw_score'   => round($score, 2),
            'max_score'   => $maxScore,
            'final_score' => round($finalScore, 2),
        ]);

        return round($finalScore, 2);
    }

    /**
     * Score the quality of a song match - now uses actual song data for comparison
     */
    public function scoreSongMatch(array $metadata, Song $song): float
    {
        $score = 0;
        $maxScore = 0;

        Log::debug('Starting song quality score calculation', [
            'song_id'         => $song->id,
            'song_title'      => $song->title,
            'song_length'     => $song->length,
            'metadata_keys'   => array_keys($metadata),
        ]);

        // Title similarity (essential field - high weight)
        if (!empty($metadata['title'])) {
            $titleSimilarity = $this->calculateStringSimilarity(
                mb_strtolower($metadata['title']),
                mb_strtolower($song->title)
            );
            $titleScore = ($titleSimilarity / 100) * 2.5; // Max 2.5 points for title
            $score += $titleScore;

            Log::debug('Song title similarity', [
                'metadata_title' => $metadata['title'],
                'song_title' => $song->title,
                'similarity' => $titleSimilarity,
                'score_added' => $titleScore,
            ]);
        }
        $maxScore += 2.5;

        // Duration matching (high weight)
        if ($this->hasDurationInfo($metadata) && $song->length) {
            $metadataLength = $this->extractDuration($metadata);
            if ($metadataLength) {
                $lengthDiff = abs($metadataLength - $song->length);
                $tolerance = max(10000, $song->length * 0.1); // 10 seconds or 10% tolerance

                if ($lengthDiff <= $tolerance) {
                    $lengthScore = (1 - ($lengthDiff / $tolerance)) * 2; // Max 2 points for duration
                    $score += $lengthScore;

                    Log::debug('Song duration match', [
                        'metadata_length' => $metadataLength,
                        'song_length' => $song->length,
                        'difference' => $lengthDiff,
                        'tolerance' => $tolerance,
                        'score_added' => $lengthScore,
                    ]);
                }
            }
        }
        $maxScore += 2;

        // Artist information matching (medium weight)
        if ($this->hasArtistInfo($metadata) && $song->artists->isNotEmpty()) {
            $artistScore = $this->calculateSongArtistSimilarity($metadata, $song);
            $score += $artistScore;

            Log::debug('Song artist similarity calculated', [
                'score_added' => $artistScore,
            ]);
        }
        $maxScore += 1.5;

        // Track position information (low weight)
        if ($this->hasTrackPosition($metadata)) {
            $score += 0.5;
            Log::debug('Track position present - added 0.5 points');
        }
        $maxScore += 0.5;

        // Genre information (low weight)
        if ($this->hasGenreInfo($metadata)) {
            $score += 0.5;
            Log::debug('Genre info present - added 0.5 points');
        }
        $maxScore += 0.5;

        // Complete metadata bonus
        if ($this->isCompleteSongMetadata($metadata)) {
            $score += 0.5;
            Log::debug('Complete song metadata bonus - added 0.5 points');
        }
        $maxScore += 0.5;

        $finalScore = $maxScore > 0 ? $score / $maxScore : 0;

        Log::debug('Song quality score calculation complete', [
            'song_id'     => $song->id,
            'raw_score'   => round($score, 2),
            'max_score'   => $maxScore,
            'final_score' => round($finalScore, 2),
        ]);

        return round($finalScore, 2);
    }

    /**
     * Calculate artist similarity for song matches
     */
    private function calculateSongArtistSimilarity(array $metadata, Song $song): float
    {
        $songArtistNames = $song->artists->pluck('name')->map(fn($name) => mb_strtolower($name))->toArray();
        $metadataArtists = $this->extractArtistNames($metadata);

        if (empty($metadataArtists) || empty($songArtistNames)) {
            return 0;
        }

        $maxSimilarity = 0;
        foreach ($metadataArtists as $metadataArtist) {
            foreach ($songArtistNames as $songArtist) {
                $similarity = $this->calculateStringSimilarity($metadataArtist, $songArtist);
                $maxSimilarity = max($maxSimilarity, $similarity);
            }
        }

        return ($maxSimilarity / 100) * 1.5; // Max 1.5 points for artist similarity
    }

    /**
     * Extract artist names from metadata
     */
    private function extractArtistNames(array $metadata): array
    {
        $artists = [];

        if (isset($metadata['artist-credit'])) {
            foreach ($metadata['artist-credit'] as $credit) {
                if (isset($credit['artist']['name'])) {
                    $artists[] = mb_strtolower($credit['artist']['name']);
                }
            }
        }

        if (isset($metadata['artists']) && is_array($metadata['artists'])) {
            foreach ($metadata['artists'] as $artist) {
                $name = is_array($artist) ? ($artist['name'] ?? '') : $artist;
                if ($name) {
                    $artists[] = mb_strtolower($name);
                }
            }
        }

        if (isset($metadata['artist']) && !empty($metadata['artist'])) {
            $artists[] = mb_strtolower($metadata['artist']);
        }

        return array_unique($artists);
    }

    /**
     * Extract duration from metadata in milliseconds
     */
    private function extractDuration(array $metadata): ?int
    {
        // MusicBrainz uses milliseconds
        if (isset($metadata['length']) && is_numeric($metadata['length'])) {
            return (int) $metadata['length'];
        }

        // Some sources might use 'duration'
        if (isset($metadata['duration']) && is_numeric($metadata['duration'])) {
            return (int) $metadata['duration'];
        }

        // Some sources might use seconds - convert to milliseconds
        if (isset($metadata['track-length']) && is_numeric($metadata['track-length'])) {
            return (int) $metadata['track-length'] * 1000;
        }

        return null;
    }

    /**
     * Check if artist metadata is comprehensive
     */
    private function isCompleteArtistMetadata(array $metadata): bool
    {
        $requiredFields = ['name'];
        $optionalFields = ['type', 'country', 'life-span', 'disambiguation', 'releases', 'tags'];

        foreach ($requiredFields as $field) {
            if (!isset($metadata[$field]) || empty($metadata[$field])) {
                return false;
            }
        }

        $presentOptionalFields = 0;
        foreach ($optionalFields as $field) {
            if (isset($metadata[$field]) && !empty($metadata[$field])) {
                $presentOptionalFields++;
            }
        }

        return $presentOptionalFields >= (count($optionalFields) * 0.4);
    }

    /**
     * Validate that a match meets minimum quality standards
     */
    public function isValidMatch(array $metadata, float $qualityScore): bool
    {
        // Minimum quality score threshold
        if ($qualityScore < 0.5) {
            return false;
        }

        // Must have basic required fields
        if (!isset($metadata['title']) && !isset($metadata['name'])) {
            return false;
        }

        return true;
    }

    /**
     * Validate that a song match meets minimum quality standards
     */
    public function isValidSongMatch(array $metadata, float $qualityScore): bool
    {
        // Minimum quality score threshold for songs
        if ($qualityScore < 0.5) {
            return false;
        }

        // Must have title
        if (!isset($metadata['title']) || empty($metadata['title'])) {
            return false;
        }

        return true;
    }

    /**
     * Check if the song metadata is complete enough for high-confidence matching
     * Now actively used in search services
     */
    public function isHighConfidenceSongMatch(array $metadata, Song $song, float $qualityScore): bool
    {
        if ($qualityScore < 0.7) {
            return false;
        }

        // High confidence requires title similarity above threshold
        if (!empty($metadata['title'])) {
            $titleSimilarity = $this->calculateStringSimilarity(
                mb_strtolower($metadata['title']),
                mb_strtolower($song->title)
            );
            if ($titleSimilarity < 80) { // Title must be very similar
                return false;
            }
        }

        // Check for additional matching criteria
        $matchingCriteria = 0;

        // Duration match
        if ($this->hasDurationInfo($metadata) && $song->length) {
            $metadataLength = $this->extractDuration($metadata);
            if ($metadataLength) {
                $lengthDiff = abs($metadataLength - $song->length);
                $tolerance = max(5000, $song->length * 0.05); // Stricter tolerance for high confidence
                if ($lengthDiff <= $tolerance) {
                    $matchingCriteria++;
                }
            }
        }

        // Artist match
        if ($this->hasArtistInfo($metadata) && $song->artists->isNotEmpty()) {
            $artistScore = $this->calculateSongArtistSimilarity($metadata, $song);
            if ($artistScore > 0.8) { // High artist similarity
                $matchingCriteria++;
            }
        }

        // Track position match (if available)
        if ($this->hasTrackPosition($metadata)) {
            $matchingCriteria++;
        }

        return $matchingCriteria >= 2; // Need at least 2 additional matching criteria
    }

    /**
     * Check if the artist metadata represents a high-confidence match
     */
    public function isHighConfidenceArtistMatch(array $metadata, Artist $artist, float $qualityScore): bool
    {
        if ($qualityScore < 0.8) {
            return false;
        }

        // High confidence requires very high name similarity
        if (!empty($metadata['name'])) {
            $nameSimilarity = $this->calculateStringSimilarity(
                mb_strtolower($metadata['name']),
                mb_strtolower($artist->name)
            );
            if ($nameSimilarity < 90) { // Name must be very similar
                return false;
            }
        }

        // Must have additional identifying information
        $hasAdditionalInfo = $this->hasDiscography($metadata) || $this->hasArtistDetails($metadata);

        return $hasAdditionalInfo;
    }

    // Private helper methods remain the same
    private function hasArtistInfo(array $metadata): bool
    {
        return isset($metadata['artist-credit']) ||
            isset($metadata['artists']) ||
            isset($metadata['artist']);
    }

    private function hasTrackListing(array $metadata): bool
    {
        return isset($metadata['media']) ||
            isset($metadata['tracklist']) ||
            isset($metadata['tracks']);
    }

    private function hasReleaseDate(array $metadata): bool
    {
        return isset($metadata['date']) ||
            isset($metadata['year']) ||
            isset($metadata['release-date']);
    }

    private function hasGenreInfo(array $metadata): bool
    {
        return isset($metadata['genres']) ||
            isset($metadata['styles']) ||
            isset($metadata['tags']);
    }

    private function hasArtwork(array $metadata): bool
    {
        return isset($metadata['cover-art-archive']) ||
            isset($metadata['images']) ||
            isset($metadata['artwork']) ||
            isset($metadata['cover_image']);
    }

    private function isCompleteMetadata(array $metadata): bool
    {
        $requiredFields = ['title'];
        $optionalFields = ['date', 'year', 'genres', 'styles', 'media', 'tracklist', 'artist-credit', 'artists'];

        if (array_any($requiredFields, fn($field) => !isset($metadata[$field]))) {
            return false;
        }

        $presentOptionalFields = 0;
        foreach ($optionalFields as $field) {
            if (isset($metadata[$field])) {
                $presentOptionalFields++;
            }
        }

        return $presentOptionalFields >= (count($optionalFields) * 0.4);
    }

    private function calculateStringSimilarity(string $str1, string $str2): float
    {
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen == 0) return 100.00;

        $distance = levenshtein($str1, $str2);
        $similarity = (($maxLen - $distance) / $maxLen) * 100;

        return round(max(0, $similarity), 2);
    }

    private function hasDiscography(array $metadata): bool
    {
        return isset($metadata['releases']) ||
            isset($metadata['albums']) ||
            isset($metadata['discography']);
    }

    private function hasArtistDetails(array $metadata): bool
    {
        return isset($metadata['type']) ||
            isset($metadata['country']) ||
            isset($metadata['life-span']) ||
            isset($metadata['disambiguation']) ||
            isset($metadata['profile']);
    }

    private function hasDurationInfo(array $metadata): bool
    {
        return isset($metadata['length']) ||
            isset($metadata['duration']) ||
            isset($metadata['track-length']);
    }

    private function hasTrackPosition(array $metadata): bool
    {
        return isset($metadata['position']) ||
            isset($metadata['track']) ||
            isset($metadata['track-number']);
    }

    private function isCompleteSongMetadata(array $metadata): bool
    {
        $requiredFields = ['title'];
        $optionalFields = ['length', 'duration', 'position', 'artist-credit', 'artists'];

        foreach ($requiredFields as $field) {
            if (!isset($metadata[$field])) {
                return false;
            }
        }

        $presentOptionalFields = 0;
        foreach ($optionalFields as $field) {
            if (isset($metadata[$field])) {
                $presentOptionalFields++;
            }
        }

        return $presentOptionalFields >= (count($optionalFields) * 0.4);
    }
}