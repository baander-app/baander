<?php

namespace App\Repositories\Cache;

use Illuminate\Support\Facades\Cache;

class LaravelCacheRepository implements CacheRepositoryInterface
{
    public function has(string $key, array $tags = []): bool
    {
        return empty($tags) ? Cache::has($key) : Cache::tags($tags)->has($key);
    }

    public function get(string $key, array $tags = [])
    {
        return empty($tags) ? Cache::get($key) : Cache::tags($tags)->get($key);
    }

    public function put(string $key, $value, int $minutes, array $tags = []): void
    {
        empty($tags) ? Cache::put($key, $value, $minutes) : Cache::tags($tags)->put($key, $value, $minutes);
    }

    public function forget(string $key, array $tags = []): void
    {
        empty($tags) ? Cache::forget($key) : Cache::tags($tags)->forget($key);
    }

    public function hashKey(string $key)
    {
        return hash('sha256', $key);
    }
}