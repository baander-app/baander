<?php

namespace Baander\RedisStack\Repository;

use Redis;
use ReflectionClass;
use Baander\RedisStack\Schema\SchemaBuilder;

class RedisRepository
{
    private Redis $redis;
    private string $prefix;

    public function __construct(Redis $redis, string $prefix)
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function save(object $entity): void
    {
        $reflection = new ReflectionClass($entity);
        $schema = SchemaBuilder::build($entity);
        $data = [];

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($entity);
            $data[$property->getName()] = $value;
        }

        $id = $data['id']; // Assuming 'id' is always present
        $redisKey = $this->prefix . ':' . $id;

        $this->redis->hMSet($redisKey, $data); // Save the entity
    }

    public function find(string $id): ?array
    {
        $data = $this->redis->hGetAll($this->prefix . ':' . $id);
        return $data ?: null;
    }

    public function remove(string $id): void
    {
        $this->redis->del($this->prefix . ':' . $id);
    }
}