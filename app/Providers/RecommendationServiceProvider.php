<?php

namespace App\Providers;

use App\Modules\Recommendation\Calculators\ContentBasedCalculator;
use App\Modules\Recommendation\Calculators\DatabaseRelationCalculator;
use App\Modules\Recommendation\Calculators\SimilarityCalculator;
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
        $this->app->singleton(RecommendationService::class, function ($app) {
            $service = new RecommendationService($app['log']);

            // Register the calculators
            $service->registerCalculator('db_relation', new DatabaseRelationCalculator());
            $service->registerCalculator('similarity', new SimilarityCalculator());
            $service->registerCalculator('content_based', new ContentBasedCalculator());

            return $service;
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}