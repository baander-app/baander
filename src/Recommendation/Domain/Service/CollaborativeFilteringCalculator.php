<?php

declare(strict_types=1);

namespace App\Recommendation\Domain\Service;

/**
 * Collaborative filtering based on user listening history.
 *
 * Uses a user-item matrix with neighborhood-based approach.
 * For a personal music server with a single primary user, this provides
 * "users who listened to X also listened to Y" style recommendations.
 */
final class CollaborativeFilteringCalculator
{
    /**
     * Calculate similarity between two users based on their listening vectors.
     *
     * @param array<string, int> $userA Map of item_id => play_count
     * @param array<string, int> $userB Map of item_id => play_count
     */
    public function userSimilarity(array $userA, array $userB): float
    {
        // Only consider items both users have interacted with
        $commonItems = array_intersect_key($userA, $userB);

        if (count($commonItems) < 2) {
            return 0.0;
        }

        $meanA = array_sum($userA) / count($userA);
        $meanB = array_sum($userB) / count($userB);

        $numerator = 0.0;
        $denomA = 0.0;
        $denomB = 0.0;

        foreach ($commonItems as $item => $count) {
            $diffA = $userA[$item] - $meanA;
            $diffB = $userB[$item] - $meanB;

            $numerator += $diffA * $diffB;
            $denomA += $diffA ** 2;
            $denomB += $diffB ** 2;
        }

        $denominator = sqrt($denomA) * sqrt($denomB);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /**
     * Predict a user's preference for items based on similar users.
     *
     * @param array<string, int> $targetUserItems Target user's item => play_count
     * @param array<string, array<string, int>> $otherUsers Map of user_id => (item_id => play_count)
     * @return array{string, float}[] Array of [item_id, predicted_score] sorted descending
     */
    public function recommend(array $targetUserItems, array $otherUsers, int $limit = 10): array
    {
        // Find items the target user hasn't listened to
        $allItems = [];
        foreach ($otherUsers as $userId => $items) {
            foreach ($items as $itemId => $playCount) {
                if (!isset($targetUserItems[$itemId])) {
                    $allItems[$itemId] = true;
                }
            }
        }

        $candidateItems = array_keys($allItems);
        if ($candidateItems === []) {
            return [];
        }

        // Precompute target user mean
        $targetMean = count($targetUserItems) > 0
            ? array_sum($targetUserItems) / count($targetUserItems)
            : 0.0;

        // Calculate user similarities and precompute per-user means
        $similarities = [];
        $userMeans = [];
        foreach ($otherUsers as $userId => $items) {
            $sim = $this->userSimilarity($targetUserItems, $items);
            if ($sim > 0.0) {
                $similarities[$userId] = $sim;
                $userMeans[$userId] = array_sum($items) / count($items);
            }
        }

        if ($similarities === []) {
            return [];
        }

        // Predict scores for each candidate item
        $predictions = [];
        foreach ($candidateItems as $itemId) {
            $numerator = 0.0;
            $denominator = 0.0;

            foreach ($similarities as $userId => $similarity) {
                $userItems = $otherUsers[$userId];
                if (!isset($userItems[$itemId])) {
                    continue;
                }

                $userMean = $userMeans[$userId];

                $numerator += $similarity * ($userItems[$itemId] - $userMean);
                $denominator += abs($similarity);
            }

            if ($denominator > 0.0) {
                $predictions[] = [
                    'id' => $itemId,
                    'score' => max(0.0, $targetMean + ($numerator / $denominator)),
                ];
            }
        }

        usort($predictions, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($predictions, 0, $limit);
    }

    /**
     * Find items frequently co-listened with a given item (item-based CF).
     *
     * @param string $itemId The source item
     * @param array<string, array<string, int>> $userHistories Map of user_id => (item_id => play_count)
     * @return array{string, float}[] Array of [item_id, co_occurrence_score]
     */
    public function coOccurrence(string $itemId, array $userHistories, int $limit = 10): array
    {
        $coOccurrences = [];

        foreach ($userHistories as $userId => $items) {
            if (!isset($items[$itemId])) {
                continue;
            }

            foreach ($items as $otherItemId => $playCount) {
                if ($otherItemId === $itemId) {
                    continue;
                }

                if (!isset($coOccurrences[$otherItemId])) {
                    $coOccurrences[$otherItemId] = 0;
                }
                $coOccurrences[$otherItemId] += $playCount;
            }
        }

        arsort($coOccurrences);

        $total = array_sum($coOccurrences) ?: 1;
        $results = [];

        foreach (array_slice($coOccurrences, 0, $limit, true) as $id => $count) {
            $results[] = [
                'id' => $id,
                'score' => $count / $total,
            ];
        }

        return $results;
    }
}
