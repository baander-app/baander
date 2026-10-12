<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Shared\Infrastructure\Redis\RedisClientFactory;
use RedisException;

/**
 * The production Redis pool with a switch that fails every borrow from a chosen one on.
 *
 * A request's Redis calls are counted so a test can fail exactly one of them, such as
 * the post-commit nonce store of a refresh, while the calls before it reach the real
 * disposable Redis. The thrown RedisException is what a refused or dropped connection
 * raises, so the production translation into the outage signal runs unchanged.
 */
final class BorrowLimitingRedisClientFactory extends RedisClientFactory
{
    private int $borrows = 0;
    private ?int $failFrom = null;

    public function borrow(callable $callback): mixed
    {
        $this->borrows++;
        if ($this->failFrom !== null && $this->borrows >= $this->failFrom) {
            throw new RedisException(sprintf('Simulated outage on borrow %d.', $this->borrows));
        }

        return parent::borrow($callback);
    }

    /** Borrows since the last reset, including the failed ones. */
    public function borrows(): int
    {
        return $this->borrows;
    }

    public function resetBorrows(): void
    {
        $this->borrows = 0;
    }

    /** Every borrow from the given one (counted from 1 after a reset) fails; null restores the real pool. */
    public function failFrom(?int $borrow): void
    {
        $this->failFrom = $borrow;
    }
}
