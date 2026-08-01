<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Service;

/**
 * Calculates similarity based on shared genres between entities.
 *
 * Accounts for genre hierarchy — parent genres contribute partial weight.
 */
final class GenreSimilarityCalculator
{
    /**
     * Compute Jaccard similarity between two genre sets.
     *
     * @param string[] $a
     * @param string[] $b
     */
    public function jaccardSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : (float) $intersection / $union;
    }

    /**
     * Compute weighted genre similarity considering genre hierarchy.
     *
     * Direct genre matches score 1.0, parent-child matches score 0.5.
     *
     * @param string[] $a
     * @param string[] $b
     * @param array<string, ?string> $parentMap Map of genre slug to parent slug
     */
    public function weightedSimilarity(array $a, array $b, array $parentMap = []): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        // Expand each set to include parents
        $aExpanded = $this->expandWithParents($a, $parentMap);
        $bExpanded = $this->expandWithParents($b, $parentMap);

        $score = 0.0;
        $aCount = count($aExpanded);
        $bCount = count($bExpanded);

        foreach ($aExpanded as $genre => $weight) {
            if (isset($bExpanded[$genre])) {
                // Average the weights
                $score += ($weight + $bExpanded[$genre]) / 2;
            }
        }

        return $score / max(1.0, (float) ($aCount + $bCount - count(array_intersect(array_keys($aExpanded), array_keys($bExpanded)))));
    }

    /**
     * @param string[] $genres
     * @param array<string, ?string> $parentMap
     * @return array<string, float> Genre => weight (1.0 for direct, 0.5 for parent)
     */
    private function expandWithParents(array $genres, array $parentMap): array
    {
        $expanded = [];

        foreach ($genres as $genre) {
            $expanded[$genre] = 1.0;

            $parent = $parentMap[$genre] ?? null;
            $depth = 1;
            while ($parent !== null && $depth <= 3) {
                $weight = 0.5 / $depth;
                $expanded[$parent] = max($expanded[$parent] ?? 0.0, $weight);
                $parent = $parentMap[$parent] ?? null;
                $depth++;
            }
        }

        return $expanded;
    }
}
