<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter\Limiter;

use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

interface RefundableRateLimiterFactoryInterface extends RateLimiterFactoryInterface
{
    public function create(?string $key = null): RefundableLimiterInterface;
}
