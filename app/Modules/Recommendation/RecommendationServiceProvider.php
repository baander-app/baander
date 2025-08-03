<?php

namespace App\Modules\Recommendation;

use App\Modules\Recommendation\Calculators\ContentBasedCalculator;
use App\Modules\Recommendation\Calculators\DatabaseRelationCalculator;
use App\Modules\Recommendation\Calculators\MusicGenreSimilarityCalculator;
use App\Modules\Recommendation\Calculators\SimilarityCalculator;
use App\Modules\Recommendation\Calculators\UserListeningHabitsCalculator;
use App\Modules\Recommendation\Services\RecommendationService;
use Illuminate\Support\ServiceProvider;

class RecommendationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->scoped(RecommendationService::class, function ($app) {
            $service = new RecommendationService($app['log']);

            // Register the calculators
            $service->registerCalculator('db_relation', new DatabaseRelationCalculator());
            $service->registerCalculator('similarity', new SimilarityCalculator());
            $service->registerCalculator('content_based', new ContentBasedCalculator());
            $service->registerCalculator('music_genre', $app->make(MusicGenreSimilarityCalculator::class));
            $service->registerCalculator('user_listening_habits', new UserListeningHabitsCalculator());

            return $service;
        });
    }
}
