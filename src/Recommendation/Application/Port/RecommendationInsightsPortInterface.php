<?php

declare(strict_types=1);

namespace App\Recommendation\Application\Port;

interface RecommendationInsightsPortInterface
{
    /**
     * @return array{total_tracks: int, tracks_with_recommendations: int, tracks_without_recommendations: int, coverage_percentage: float}
     */
    public function getCoverage(): array;

    /**
     * @return array{by_source_type: array<string, int>, avg_confidence_score: float}
     */
    public function getSourceQuality(): array;

    /**
     * @return array{avg_age_seconds: float, last_generated_at: string|null}
     */
    public function getFreshness(): array;
}
