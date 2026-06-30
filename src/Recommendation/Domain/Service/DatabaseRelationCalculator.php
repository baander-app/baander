<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Service;

/**
 * Calculates similarity based on shared database relationships.
 *
 * Considers: shared artists, shared genres, shared albums between entities.
 */
final class DatabaseRelationCalculator
{
    /**
     * Calculate relationship score based on shared entity IDs.
     *
     * @param string[] $aIds Entity IDs from source A
     * @param string[] $bIds Entity IDs from source B
     * @param int $totalPool Total number of entities in the pool (for IDF weighting)
     */
    public function sharedEntityScore(array $aIds, array $bIds, int $totalPool = 1000): float
    {
        if ($aIds === [] || $bIds === []) {
            return 0.0;
        }

        $intersection = array_intersect($aIds, $bIds);
        $count = count($intersection);

        if ($count === 0) {
            return 0.0;
        }

        // TF-IDF inspired weighting
        $idfA = log(($totalPool + 1) / (count($aIds) + 1));
        $idfB = log(($totalPool + 1) / (count($bIds) + 1));

        return (float) $count * $idfA * $idfB;
    }

    /**
     * Compute combined relationship score from multiple relation types.
     *
     * @param array<string, float> $scores Map of relation type to its score
     * @param array<string, float> $weights Map of relation type to its weight
     */
    public function combinedScore(array $scores, array $weights = []): float
    {
        if ($scores === []) {
            return 0.0;
        }

        $defaultWeights = [
            'artist' => 0.4,
            'genre' => 0.3,
            'album' => 0.2,
            'tag' => 0.1,
        ];

        $weights = array_merge($defaultWeights, $weights);

        $total = 0.0;
        foreach ($scores as $type => $score) {
            $weight = $weights[$type] ?? 0.0;
            $total += $score * $weight;
        }

        return min(1.0, $total);
    }
}
