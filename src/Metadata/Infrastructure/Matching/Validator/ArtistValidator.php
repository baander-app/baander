<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Matching\Validator;

/**
 * Stateless validator that scores how well an extracted artist name matches a candidate.
 *
 * Scoring tiers (best match wins):
 *   1.0  - Exact string match
 *   0.95 - Case-insensitive exact match
 *   0.0-0.9 - Levenshtein similarity ratio
 *   0.7  - One string contains the other
 *
 * Handles collaborative credits ("feat.", "vs.", "&") by comparing only the
 * primary (first) artist before those separators.
 */
final class ArtistValidator
{
    /**
     * Compute a confidence score between 0.0 and 1.0.
     */
    public function validate(string $extractedArtist, string $candidateArtist): float
    {
        $primaryExtracted = $this->extractPrimaryArtist($extractedArtist);
        $primaryCandidate = $this->extractPrimaryArtist($candidateArtist);

        // Exact match
        if ($primaryExtracted === $primaryCandidate) {
            return 1.0;
        }

        $normExtracted = strtolower(trim($primaryExtracted));
        $normCandidate = strtolower(trim($primaryCandidate));

        // Case-insensitive exact match
        if ($normExtracted === $normCandidate) {
            return 0.95;
        }

        // Both strings must be non-empty for Levenshtein comparison
        if ($normExtracted !== '' && $normCandidate !== '') {
            $similarity = $this->levenshteinSimilarity($normExtracted, $normCandidate);

            if ($similarity >= 0.85) {
                // Scale into the 0.7-0.9 range based on similarity
                return 0.7 + ($similarity - 0.85) * (0.2 / 0.15);
            }

            if ($similarity >= 0.6) {
                // Scale into the 0.3-0.7 range
                return 0.3 + ($similarity - 0.6) * (0.4 / 0.25);
            }

            // Low similarity but still recognizable
            if ($similarity >= 0.4) {
                return ($similarity - 0.4) * (0.3 / 0.2);
            }
        }

        // Contains check: one name is a substring of the other
        if (str_contains($normExtracted, $normCandidate) || str_contains($normCandidate, $normExtracted)) {
            return 0.7;
        }

        return 0.0;
    }

    /**
     * Strip collaborative separators and return only the primary artist.
     *
     * Handles: "feat.", "ft.", "featuring", "vs.", "&", ","
     */
    private function extractPrimaryArtist(string $artist): string
    {
        $artist = trim($artist);

        $patterns = [
            '/\s+feat\.?\s+/i',
            '/\s+ft\.?\s+/i',
            '/\s+featuring\s+/i',
            '/\s+vs\.?\s+/i',
            '/\s*[&,]\s*/',
        ];

        foreach ($patterns as $pattern) {
            $artist = preg_replace($pattern, ' ', $artist);
        }

        return trim($artist);
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
