<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Limiter;

use Symfony\Component\RateLimiter\LimiterInterface;

/** A limiter that can give back tokens an attempt took and turned out not to use. */
interface RefundableLimiterInterface extends LimiterInterface
{
    /**
     * Gives back $tokens that an earlier consume or reserve of this subject took. The count
     * never goes below zero: after a reset, a named clear or the end of the window that held
     * them, there is nothing left to give back.
     *
     * @throws \InvalidArgumentException when $tokens is negative or over the limit
     */
    public function refund(int $tokens = 1): void;
}
