<?php

namespace App\Modules\DeviceDetector;

use DeviceDetector\Cache\CacheInterface;
use Illuminate\Cache\Repository;

class CacheRepository implements CacheInterface
{
    public function __construct(
        protected Repository $cache,
    )
    {
    }

    public function fetch(string $id)
    {
        return $this->cache->get($id);
    }

    public function contains(string $id): bool
    {
        return $this->cache->has($id);
    }

    public function save(string $id, $data, int $lifeTime = 3600): bool
    {
        return $this->cache->put($id, $data, $lifeTime);
    }

    public function delete(string $id): bool
    {
        return $this->cache->forget($id);
    }

    public function flushAll(): bool
    {
        return $this->cache->flush();
    }
}