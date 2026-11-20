<?php

declare(strict_types=1);

namespace Baander\RedisRateLimiter;

/** How the atomic rate limiters read a configured interval such as `1 second` or `15 minutes`. */
final class RateLimiterInterval
{
    /** Whole seconds in the interval, measured from the current second. */
    public static function seconds(string $interval): int
    {
        $now = new \DateTimeImmutable('@' . time());

        return $now->modify('+' . $interval)->getTimestamp() - $now->getTimestamp();
    }
}
