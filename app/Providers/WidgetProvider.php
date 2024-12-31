<?php

namespace App\Providers;

use App\Services\Widgets\{WidgetService, WidgetBuilderMap, WidgetTypeProvider};
use Illuminate\Support\ServiceProvider;

class WidgetProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->when(WidgetService::class)
            ->needs(WidgetBuilderMap::class)
            ->give(function () {
                $map = [];

                foreach (config('widgets.builders') as $type => $builder) {
                    $map[$type] = $builder;
                }

                return new WidgetBuilderMap($map);
            });
    }

    public function boot()
    {
        $this->app->singleton(WidgetTypeProvider::class, WidgetTypeProvider::class);
    }
}
