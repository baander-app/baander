<?php

namespace App\Modules\TextSimilarity;

use Illuminate\Support\ServiceProvider;

class TextSimilarityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TextSimilarityService::class, function ($app) {
            return new TextSimilarityService();
        });
    }

    public function provides(): array
    {
        return [TextSimilarityService::class];
    }
}