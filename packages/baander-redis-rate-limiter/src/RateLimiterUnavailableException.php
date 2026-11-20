<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter;

use RuntimeException;

/** No limiter verdict is available; storage failure must not grant a fresh budget. */
final class RateLimiterUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Rate limiting is temporarily unavailable.');
    }
}
