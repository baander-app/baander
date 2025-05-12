<?php

namespace Baander\RedisStack;

use Baander\RedisStack\Index\IndexManager;
use Baander\RedisStack\Search\SearchManager;
use Baander\RedisStack\Suggestions\SuggestionManager;
use Psr\Log\LoggerInterface;
use Redis;

class RedisStack
{
    protected Redis $redis;
    private static LoggerInterface $logger;

    public function __construct(Redis $redis, ?LoggerInterface $logger)
    {
        $this->redis = $redis;
        self::$logger = $logger;
    }

    public static function getLogger(): ?LoggerInterface
    {
        return self::$logger;
    }

    public function getRedis(): Redis
    {
        return $this->redis;
    }


    public function indexes(): IndexManager
    {
        return new IndexManager($this->redis);
    }

    public function search(): SearchManager
    {
        return new SearchManager($this->redis);
    }

    public function suggestions()
    {
        return new SuggestionManager($this->redis);
    }

    /**
     * Flush all indexes and associated data.
     * For high-level convenience, wraps Redis flush commands.
     *
     */
    public function flush(): bool
    {
        try {
            // Adjust this based on your actual Redis setup.
            $this->redis->flushAll();
            return true;
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not flush database: ' . $e->getMessage());
        }
    }
}