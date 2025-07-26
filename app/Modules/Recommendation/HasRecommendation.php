<?php

namespace App\Modules\Recommendation;

use App\Jobs\Recommendation\GenerateRecommendationsJob;
use App\Modules\Recommendation\Services\RecommendationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

trait HasRecommendation
{
    /**
     * Get recommendations for this model
     *
     * @param string $recommendationName
     * @return Collection
     */
    public function getRecommendations(string $recommendationName): Collection
    {
        return App::make(RecommendationService::class)
            ->getRecommendations($this, $recommendationName);
    }

    /**
     * Get recommendations with specified relationships loaded
     *
     * @param string $recommendationName
     * @param array $relationships
     * @return Collection
     */
    public function getRecommendationsWithRelations(string $recommendationName, array $relationships): Collection
    {
        $recommendations = $this->getRecommendations($recommendationName);

        return $recommendations->isEmpty()
            ? $recommendations
            : $recommendations->load($relationships);
    }

    /**
     * Generate recommendations for this specific model instance
     *
     * @param string $recommendationName
     * @return int Number of recommendations generated
     */
    public function generateRecommendationsForSelf(string $recommendationName): int
    {
        return App::make(RecommendationService::class)
            ->generateRecommendationsForModel($this, $recommendationName);
    }

    /**
     * Generate recommendations for all models of this class
     *
     * @param string $recommendationName
     * @return int Number of recommendations generated
     */
    public static function generateRecommendations(string $recommendationName, array $options = []): int
    {
        return App::make(RecommendationService::class)
            ->generateRecommendations(static::class, $recommendationName, $options);
    }

    /**
     * Schedule recommendation generation as a background job
     *
     * @param string $recommendationName
     * @return void
     */
    public static function scheduleRecommendationGeneration(string $recommendationName): void
    {
        dispatch(new GenerateRecommendationsJob(static::class, $recommendationName));
    }

    /**
     * Clear recommendation cache for all models of this class
     *
     * @param string $recommendationName
     * @return void
     */
    public static function clearRecommendationCache(string $recommendationName): void
    {
        App::make(RecommendationService::class)
            ->clearRecommendationCache(static::class, $recommendationName);
    }
}