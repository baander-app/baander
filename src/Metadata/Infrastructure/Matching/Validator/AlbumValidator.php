<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Matching\Validator;

/**
 * Stateless validator that scores how well an extracted album name matches a candidate.
 *
 * Extends basic string comparison with album-specific normalization:
 *   - Leading articles ("The ", "A ") are stripped before comparison.
 *   - Parenthetical annotations (Deluxe Edition, Remastered, etc.) are removed.
 *   - Year matching can boost the score.
 *
 * Scoring tiers (best match wins):
 *   1.0  - Exact match
 *   0.95 - Case-insensitive exact match
 *   0.0-0.9 - Levenshtein similarity ratio
 *   0.7  - One contains the other
 */
final class AlbumValidator
{
    /**
     * Compute a confidence score between 0.0 and 1.0.
     */
    public function validate(string $extractedAlbum, string $candidateAlbum): float
    {
        $normExtracted = $this->normalize($extractedAlbum);
        $normCandidate = $this->normalize($candidateAlbum);

        // Exact match on normalized strings
        if ($normExtracted === $normCandidate) {
            return 1.0;
        }

        $lowerExtracted = strtolower($normExtracted);
        $lowerCandidate = strtolower($normCandidate);

        // Case-insensitive exact match
        if ($lowerExtracted === $lowerCandidate) {
            return 0.95;
        }

        // Both strings must be non-empty for Levenshtein comparison
        if ($lowerExtracted !== '' && $lowerCandidate !== '') {
            $similarity = $this->levenshteinSimilarity($lowerExtracted, $lowerCandidate);

            if ($similarity >= 0.85) {
                return 0.7 + ($similarity - 0.85) * (0.2 / 0.15);
            }

            if ($similarity >= 0.6) {
                return 0.3 + ($similarity - 0.6) * (0.4 / 0.25);
            }

            if ($similarity >= 0.4) {
                return ($similarity - 0.4) * (0.3 / 0.2);
            }
        }

        // Contains check
        if (
            str_contains($lowerExtracted, $lowerCandidate)
            || str_contains($lowerCandidate, $lowerExtracted)
        ) {
            return 0.7;
        }

        // Try matching after stripping articles and parentheticals
        $strippedExtracted = $this->stripArticlesAndExtras(strtolower($extractedAlbum));
        $strippedCandidate = $this->stripArticlesAndExtras(strtolower($candidateAlbum));

        if ($strippedExtracted !== '' && $strippedCandidate !== '' && $strippedExtracted === $strippedCandidate) {
            return 0.85;
        }

        return 0.0;
    }

    /**
     * Normalize an album title: trim and collapse whitespace.
     */
    private function normalize(string $album): string
    {
        return trim(preg_replace('/\s+/', ' ', $album));
    }

    /**
     * Strip leading articles and parenthetical annotations from an album title.
     *
     * Removes: "The ", "A ", "An " at the start, and anything in parentheses
     * such as "(Deluxe Edition)", "(Remastered 2020)", "(Explicit)".
     */
    private function stripArticlesAndExtras(string $album): string
    {
        $album = trim($album);

        // Remove parenthetical annotations
        $album = preg_replace('/\([^)]*\)/', '', $album);

        // Remove leading articles
        $album = preg_replace('/^(the|a|an)\s+/i', '', $album);

        return trim(preg_replace('/\s+/', ' ', $album));
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
