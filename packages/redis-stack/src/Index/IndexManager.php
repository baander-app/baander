<?php

namespace Baander\RedisStack\Index;

use Exception;
use Redis;

class IndexManager
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function createIndex(IndexDefinition $definition): void
    {
        $command = $definition->generateCommand();
        $this->redis->rawCommand('FT.CREATE', ...$command);
    }

    public function dropIndex(string $indexName): void
    {
        $this->redis->rawCommand('FT.DROPINDEX', $indexName);
    }

    public function indexExists(string $indexName): bool
    {
        try {
            $this->redis->rawCommand('FT.INFO', $indexName);
            return true;
        } catch (Exception) {
            return false;
        }
    }

    public function addAlias(string $indexName, string $alias): void
    {
        $this->redis->rawCommand('FT.ALIASADD', $alias, $indexName);
    }

    public function updateAlias(string $indexName, string $alias): void
    {
        $this->redis->rawCommand('FT.ALIASUPDATE', $alias, $indexName);
    }

    public function deleteAlias(string $alias): void
    {
        $this->redis->rawCommand('FT.ALIASDEL', $alias);
    }
}