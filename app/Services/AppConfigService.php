<?php

namespace App\Services;

use App\Baander;
use App\Http\Data\AppConfig\AppConfigData;
use App\Http\Data\AppConfig\TracingConfigData;
use Illuminate\Support\Facades\Cache;

class AppConfigService
{
    public function getAppConfig(?string $cacheKey = 'app_config'): AppConfigData
    {
        if ($cacheKey && config('app.env') === 'prod') {
            return Cache::remember($cacheKey, now()->addMinutes(60), function () {
                return $this->buildConfigData();
            });
        }

        return $this->buildConfigData();
    }

    private function buildConfigData(): AppConfigData
    {
        return new AppConfigData(
            name: config('app.name'),
            url: config('app.url'),
            apiUrl: config('app.url') . '/api',
            environment: config('app.env'),
            debug: config('app.debug'),
            locale: config('app.locale'),
            version: Baander::VERSION,
            tracing: $this->getTracingConfig(),
        );
    }

    /**
     * Get tracing configuration
     */
    private function getTracingConfig(): TracingConfigData
    {
        return new TracingConfigData(
            enabled: config('otel.frontend.enabled', false),
            url: config('otel.frontend.url', null),
            token: config('otel.frontend.token', null),
        );
    }

    /**
     * Clear the cached configuration
     */
    public function clearCache(string $cacheKey = 'app_config'): void
    {
        Cache::forget($cacheKey);
    }
}
