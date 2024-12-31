<?php

namespace App\Http\Integrations;

use Illuminate\Support\Facades\Cache;
use Saloon\CachePlugin\Contracts\Driver;
use Saloon\CachePlugin\Drivers\LaravelCacheDriver;
use Saloon\CachePlugin\Traits\HasCaching;

trait CachesSaloonRequests
{
    use HasCaching;

    public function resolveCacheDriver(): Driver
    {
        return app(\Saloon\CachePlugin\Drivers\LaravelCacheDriver::class);
    }
}