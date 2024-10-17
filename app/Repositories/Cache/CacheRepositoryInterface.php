<?php

namespace App\Repositories\Cache;

interface CacheRepositoryInterface
{
    public function has(string $key, array $tags = []): bool;

    public function get(string $key, array $tags = []);

    public function put(string $key, $value, int $minutes, array $tags = []): void;

    public function forget(string $key, array $tags = []): void;
}