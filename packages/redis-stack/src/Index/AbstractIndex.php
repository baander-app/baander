<?php

namespace Baander\RedisStack\Index;

use Redis;
use RedisException;

abstract class AbstractIndex
{
    protected Redis $redis;
    protected string $indexName;

    public function __construct(Redis $redis, string $indexName)
    {
        $this->redis = $redis;
        $this->indexName = $indexName;
    }

    public function getIndexName(): string
    {
        return $this->indexName;
    }

    protected function rawCommand(string $command, array $arguments = []): mixed
    {
        try {
            return $this->redis->rawCommand($command, ...$arguments);
        } catch (RedisException $exception) {
            throw new \RuntimeException("Redis command failed: {$exception->getMessage()}");
        }
    }
}