<?php

namespace App\Services;

use App\Baander;
use App\Http\Data\AppConfig\AppConfigData;
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

    public function getOAuthConfig()
    {
        return [
            'client_id'              => 'spa-client-' . config('app.name', 'baander'),
            'authorization_endpoint' => config('app.url') . '/api/oauth/spa/authorize',
            'token_endpoint'         => config('app.url') . '/api/oauth/token',
            'scopes'                 => ['read', 'write', 'stream'],
        ];
    }

    private function buildConfigData(): AppConfigData
    {
        return new AppConfigData(
            name: config('app.name'),
            url: config('app.url'),
            apiUrl: config('app.url') . '/api',
            apiDocsUrl: secure_url('/docs/api.json'),
            environment: config('app.env'),
            debug: config('app.debug'),
            locale: config('app.locale'),
            version: Baander::VERSION,
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
