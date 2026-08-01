<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Service;

/**
 * Calculates content-based similarity using audio feature vectors.
 *
 * Uses cosine similarity between feature vectors (energy, danceability,
 * valence, acousticness, instrumentalness, spechiness, tempo, loudness).
 */
final class ContentSimilarityCalculator
{
    /**
     * Compute cosine similarity between two feature vectors.
     *
     * @param array<string, float> $a Feature vector (keyed by feature name)
     * @param array<string, float> $b Feature vector (keyed by feature name)
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $keys = array_intersect(array_keys($a), array_keys($b));

        if ($keys === []) {
            return 0.0;
        }

        // Dot product uses only intersecting keys
        $dotProduct = 0.0;
        foreach ($keys as $key) {
            $dotProduct += $a[$key] * $b[$key];
        }

        // Norms use only intersecting dimensions
        $normA = 0.0;
        $normB = 0.0;
        foreach ($keys as $key) {
            $normA += $a[$key] ** 2;
            $normB += $b[$key] ** 2;
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $dotProduct / $denominator;
    }

    /**
     * Compute Euclidean distance between two feature vectors.
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    public function euclideanDistance(array $a, array $b): float
    {
        $keys = array_intersect(array_keys($a), array_keys($b));

        if ($keys === []) {
            return INF;
        }

        $sum = 0.0;
        foreach ($keys as $key) {
            $sum += ($a[$key] - $b[$key]) ** 2;
        }

        return sqrt($sum);
    }

    /**
     * Find the most similar items to a given feature vector.
     *
     * @param array<string, float> $target
     * @param array<int, array{id: string, features: array<string, float>}> $candidates
     * @return array{string, float}[] Array of [id, similarity] sorted descending
     */
    public function findMostSimilar(array $target, array $candidates, int $limit = 10): array
    {
        $scores = [];
        foreach ($candidates as $candidate) {
            $similarity = $this->cosineSimilarity($target, $candidate['features']);
            $scores[] = ['id' => $candidate['id'], 'score' => $similarity];
        }

        usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scores, 0, $limit);
    }
}
