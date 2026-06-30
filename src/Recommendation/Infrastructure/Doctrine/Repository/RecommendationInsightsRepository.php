<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Doctrine\Repository;

use App\Recommendation\Application\Port\RecommendationInsightsPortInterface;
use Doctrine\ORM\EntityManagerInterface;

final class RecommendationInsightsRepository implements RecommendationInsightsPortInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getCoverage(): array
    {
        $conn = $this->entityManager->getConnection();

        $totalTracks = (int) $conn->executeQuery(
            'SELECT COUNT(*) FROM songs',
        )->fetchOne();

        $tracksWithRecommendations = (int) $conn->executeQuery(
            "SELECT COUNT(DISTINCT r.target_id) FROM recommendations r WHERE r.target_type = 'song'",
        )->fetchOne();

        $tracksWithoutRecommendations = max(0, $totalTracks - $tracksWithRecommendations);
        $coveragePercentage = $totalTracks > 0
            ? round(($tracksWithRecommendations / $totalTracks) * 100, 2)
            : 0.0;

        return [
            'total_tracks' => $totalTracks,
            'tracks_with_recommendations' => $tracksWithRecommendations,
            'tracks_without_recommendations' => $tracksWithoutRecommendations,
            'coverage_percentage' => $coveragePercentage,
        ];
    }

    public function getSourceQuality(): array
    {
        $conn = $this->entityManager->getConnection();

        $bySourceType = $conn->executeQuery(
            'SELECT source_type, COUNT(*) as cnt FROM recommendations GROUP BY source_type',
        )->fetchAllAssociativeIndexed();

        $counts = [];
        foreach ($bySourceType as $sourceType => $row) {
            $counts[$sourceType] = (int) $row['cnt'];
        }

        $avgScore = (float) $conn->executeQuery(
            'SELECT COALESCE(AVG(score), 0) FROM recommendations',
        )->fetchOne();

        return [
            'by_source_type' => $counts,
            'avg_confidence_score' => round($avgScore, 4),
        ];
    }

    public function getFreshness(): array
    {
        $conn = $this->entityManager->getConnection();

        $avgAge = (float) $conn->executeQuery(
            "SELECT COALESCE(AVG(EXTRACT(EPOCH FROM (NOW() - updated_at))), 0) FROM recommendations",
        )->fetchOne();

        $lastGenerated = $conn->executeQuery(
            'SELECT MAX(updated_at) FROM recommendations',
        )->fetchOne();

        return [
            'avg_age_seconds' => round($avgAge, 2),
            'last_generated_at' => $lastGenerated !== null ? (string) $lastGenerated : null,
        ];
    }
}
