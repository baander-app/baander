<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;

/**
 * Wraps a Redis connection checked out from the pool.
 *
 * Delegates all Redis method calls via __call(). The caller must call
 * release() when done. If release() is not called, the destructor will
 * return the connection to the pool with a warning log.
 */
final class ManagedRedisConnection
{
    private bool $released = false;

    public function __construct(
        private Redis $redis,
        private readonly RedisClientFactory $pool,
        private readonly int $coroutineId,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Delegates method calls to the underlying Redis connection.
     *
     * @throws LogicException if called after release()
     */
    public function __call(string $method, array $args): mixed
    {
        if ($this->released) {
            throw new LogicException('Connection already released');
        }

        return $this->redis->{$method}(...$args);
    }

    /**
     * Returns the connection to the pool. Safe to call multiple times.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->pool->returnConnection($this->redis);
    }

    /**
     * Safety net: returns connection to pool if caller forgot release().
     */
    public function __destruct()
    {
        if (!$this->released) {
            $this->logger->warning('ManagedRedisConnection released by destructor — caller forgot to call release()', [
                'coroutineId' => $this->coroutineId,
            ]);
            $this->release();
        }
    }
}
