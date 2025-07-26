<?php

namespace App\Modules\OpenTelemetry;

use App\Modules\OpenTelemetry\Listeners\DatabaseQueryListener;
use App\Modules\OpenTelemetry\Middleware\HttpInstrumentationMiddleware;
use Illuminate\Support\ServiceProvider;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OpenTelemetryManager::class, function ($app) {
            return new OpenTelemetryManager();
        });

        $this->app->scoped(HttpInstrumentationMiddleware::class, function ($app) {
            return new HttpInstrumentationMiddleware($app->make(OpenTelemetryManager::class));
        });
    }

    public function boot(): void
    {
        if (!config('app.otel_enabled', true)) {
            return;
        }

        // Register database query listener
        $this->app->make(DatabaseQueryListener::class)->register();
    }
}