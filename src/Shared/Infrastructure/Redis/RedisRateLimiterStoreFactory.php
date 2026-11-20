<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

use Baander\RedisRateLimiter\Exception\RateLimiterUnavailableException;
use Baander\RedisRateLimiter\Storage\RedisRateLimiterStore;

/** Keeps the application's pool lifecycle outside the rate limiter package. */
final readonly class RedisRateLimiterStoreFactory
{
    public function __construct(private RedisClientFactory $redis) {}

    public function create(): RedisRateLimiterStore
    {
        return new RedisRateLimiterStore(function (callable $operation): mixed {
            try {
                return $this->redis->borrow($operation);
            } catch (RedisPoolExhaustedException) {
                throw new RateLimiterUnavailableException();
            }
        });
    }
}
