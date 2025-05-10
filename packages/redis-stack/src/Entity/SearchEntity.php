<?php

namespace Baander\RedisStack\Entity;

use Redis;

class SearchEntity
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Save an entity into a RedisSearch index.
     */
    public function save(string $key, array $data): void
    {
        $this->redis->hMSet($key, $data);
    }

    /**
     * Delete an entity by its key.
     */
    public function delete(string $key): void
    {
        $this->redis->del($key);
    }

    /**
     * Load an entity by its key.
     *
     * @param string $key
     * @return array|null
     */
    public function load(string $key): ?array
    {
        $data = $this->redis->hGetAll($key);
        return $data ?: null;
    }
}