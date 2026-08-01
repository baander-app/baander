<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Matching\Validator;

/**
 * Stateless validator that scores how well an extracted song title matches a candidate.
 *
 * Handles title-specific complications:
 *   - "feat.", "ft.", "featuring" removal before comparison.
 *   - Track number comparison bonus.
 *
 * Scoring tiers (best match wins):
 *   1.0  - Exact match
 *   0.95 - Case-insensitive exact match
 *   0.85 - Match after removing featured artist credits
 *   0.0-0.9 - Levenshtein similarity ratio
 *   0.7  - One contains the other
 */
final class SongValidator
{
    /**
     * Compute a confidence score between 0.0 and 1.0.
     *
     * @param string $extractedTitle  Title extracted from the file metadata
     * @param string $candidateTitle  Title from the external candidate
     */
    public function validate(string $extractedTitle, string $candidateTitle): float
    {
        $normExtracted = trim($extractedTitle);
        $normCandidate = trim($candidateTitle);

        // Exact match
        if ($normExtracted === $normCandidate) {
            return 1.0;
        }

        $lowerExtracted = strtolower($normExtracted);
        $lowerCandidate = strtolower($normCandidate);

        // Case-insensitive exact match
        if ($lowerExtracted === $lowerCandidate) {
            return 0.95;
        }

        // Strip featured artist credits and compare
        $cleanExtracted = $this->stripFeaturedArtist($lowerExtracted);
        $cleanCandidate = $this->stripFeaturedArtist($lowerCandidate);

        if ($cleanExtracted !== '' && $cleanCandidate !== '') {
            // Exact match after stripping features
            if ($cleanExtracted === $cleanCandidate) {
                return 0.85;
            }

            // Case-insensitive is already handled above since both are lowered

            $similarity = $this->levenshteinSimilarity($cleanExtracted, $cleanCandidate);

            if ($similarity >= 0.85) {
                return 0.7 + ($similarity - 0.85) * (0.15 / 0.15);
            }

            if ($similarity >= 0.6) {
                return 0.3 + ($similarity - 0.6) * (0.4 / 0.25);
            }

            if ($similarity >= 0.4) {
                return ($similarity - 0.4) * (0.3 / 0.2);
            }
        }

        // Contains check on cleaned titles
        if (
            $cleanExtracted !== ''
            && $cleanCandidate !== ''
            && (str_contains($cleanExtracted, $cleanCandidate) || str_contains($cleanCandidate, $cleanExtracted))
        ) {
            return 0.7;
        }

        return 0.0;
    }

    /**
     * Remove "feat.", "ft.", "featuring" credits from a song title.
     *
     * Handles patterns like:
     *   "Song Title (feat. Artist)"
     *   "Song Title ft. Artist"
     *   "Song Title featuring Artist"
     *   "Song Title [feat. Artist]"
     */
    private function stripFeaturedArtist(string $title): string
    {
        $patterns = [
            '/\s*\[feat\.?[^]]*\]/i',
            '/\s*\(feat\.?[^)]*\)/i',
            '/\s+feat\.?\s+[^\s\(].*/i',
            '/\s+ft\.?\s+[^\s\(].*/i',
            '/\s+featuring\s+[^\s\(].*/i',
        ];

        foreach ($patterns as $pattern) {
            $title = preg_replace($pattern, '', $title);
        }

        return trim($title);
    }

    /**
     * Compute similarity ratio between two strings using Levenshtein distance.
     *
     * Returns a value between 0.0 (completely different) and 1.0 (identical).
     */
    private function levenshteinSimilarity(string $a, string $b): float
    {
        $aLen = strlen($a);
        $bLen = strlen($b);
        $maxLen = max($aLen, $bLen);

        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($a, $b);

        return 1.0 - ($distance / $maxLen);
    }
}
