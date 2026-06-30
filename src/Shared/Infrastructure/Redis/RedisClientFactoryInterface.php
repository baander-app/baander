<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

/**
 * Abstraction over RedisClientFactory for injection into handlers.
 * RedisClientFactory has constructor dependencies (DSN) that make it
 * hard to construct in tests — use this interface when mocking.
 */
interface RedisClientFactoryInterface
{
    /**
     * @template T
     * @param callable(\Redis): T $callback
     * @return T
     */
    public function borrow(callable $callback): mixed;
}
