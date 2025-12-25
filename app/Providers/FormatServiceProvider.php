<?php

namespace App\Providers;

use App\Format\TextSimilarity;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for formatting classes.
 */
class FormatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TextSimilarity::class, function ($app) {
            return new TextSimilarity();
        });
    }

    public function provides(): array
    {
        return [TextSimilarity::class];
    }
}
