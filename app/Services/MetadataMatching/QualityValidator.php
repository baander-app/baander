<?php

namespace App\Services\MetadataMatching;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Song;

class QualityValidator
{
    /**
     * Score the quality of an album match
     */
    public function scoreAlbumMatch(array $metadata, Album $album): float
    {
        $score = 0;
        $factors = 0;

        // Check if essential fields are present
        if (!empty($metadata['title'])) {
            $score += 1;
        }
        $factors++;

        // Check if artist information is present
        if ($this->hasArtistInfo($metadata)) {
            $score += 1;
        }
        $factors++;

        // Check if track listing is present
        if ($this->hasTrackListing($metadata)) {
            $score += 1;
        }
        $factors++;

        // Check if release date is present
        if ($this->hasReleaseDate($metadata)) {
            $score += 1;
        }
        $factors++;

        // Check if genre information is present
        if ($this->hasGenreInfo($metadata)) {
            $score += 0.5;
        }
        $factors += 0.5;

        // Bonus for complete metadata
        if ($this->isCompleteMetadata($metadata)) {
            $score += 0.5;
        }
        $factors += 0.5;

        return $factors > 0 ? $score / $factors : 0;
    }

    /**
     * Score the quality of an artist match
     */
    public function scoreArtistMatch(array $metadata, Artist $artist): float
    {
        $score = 0;
        $factors = 0;

        // Check if name is present
        if (isset($metadata['name']) && !empty($metadata['name'])) {
            $score += 1;
        }
        $factors++;

        // Check if discography is present
        if ($this->hasDiscography($metadata)) {
            $score += 1;
        }
        $factors++;

        // Check if additional info is present (bio, type, etc.)
        if ($this->hasArtistDetails($metadata)) {
            $score += 0.5;
        }
        $factors += 0.5;

        return $factors > 0 ? $score / $factors : 0;
    }

    /**
     * Validate that a match meets minimum quality standards
     */
    public function isValidMatch(array $metadata, float $qualityScore): bool
    {
        // Minimum quality score threshold
        if ($qualityScore < 0.6) {
            return false;
        }

        // Must have basic required fields
        if (!isset($metadata['title']) && !isset($metadata['name'])) {
            return false;
        }

        return true;
    }

    /**
     * Score the quality of a song match
     */
    public function scoreSongMatch(array $metadata, Song $song): float
    {
        $score = 0;
        $factors = 0;

        // Check if essential fields are present
        if (!empty($metadata['title'])) {
            $score += 1;
        }
        $factors++;

        // Check if duration information is present
        if ($this->hasDurationInfo($metadata)) {
            $score += 1;
        }
        $factors++;

        // Check if artist information is present
        if ($this->hasArtistInfo($metadata)) {
            $score += 1;
        }
        $factors++;

        // Check if track position is present
        if ($this->hasTrackPosition($metadata)) {
            $score += 0.5;
        }
        $factors += 0.5;

        // Check if genre information is present
        if ($this->hasGenreInfo($metadata)) {
            $score += 0.5;
        }
        $factors += 0.5;

        // Bonus for complete song metadata
        if ($this->isCompleteSongMetadata($metadata)) {
            $score += 0.5;
        }
        $factors += 0.5;

        return $factors > 0 ? $score / $factors : 0;
    }

    /**
     * Validate that a song match meets minimum quality standards
     */
    public function isValidSongMatch(array $metadata, float $qualityScore): bool
    {
        // Minimum quality score threshold for songs
        if ($qualityScore < 0.6) {
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
     */
    public function isHighConfidenceSongMatch(array $metadata, Song $song, float $qualityScore): bool
    {
        if ($qualityScore < 0.8) {
            return false;
        }

        // High confidence requires title and at least one additional matching field
        $hasTitle = isset($metadata['title']) && !empty($metadata['title']);
        $hasDuration = $this->hasDurationInfo($metadata);
        $hasArtist = $this->hasArtistInfo($metadata);
        $hasPosition = $this->hasTrackPosition($metadata);

        $matchingFields = 0;
        if ($hasTitle) $matchingFields++;
        if ($hasDuration) $matchingFields++;
        if ($hasArtist) $matchingFields++;
        if ($hasPosition) $matchingFields++;

        return $matchingFields >= 3; // Need at least 3 matching fields
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

        if (array_any($requiredFields, fn($field) => !isset($metadata[$field]))) {
            return false;
        }

        $presentOptionalFields = 0;
        foreach ($optionalFields as $field) {
            if (isset($metadata[$field])) {
                $presentOptionalFields++;
            }
        }

        // Consider complete if at least 60% of optional fields are present
        return $presentOptionalFields >= (count($optionalFields) * 0.6);
    }

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

    private function isCompleteMetadata(array $metadata): bool
    {
        $requiredFields = ['title'];
        $optionalFields = ['date', 'year', 'genres', 'styles', 'media', 'tracklist'];

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

        // Consider complete if at least 50% of optional fields are present
        return $presentOptionalFields >= (count($optionalFields) * 0.5);
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
}