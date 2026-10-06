<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

use RuntimeException;

final class RateLimiterClearFailedException extends RuntimeException
{
    /** @param list<string> $names */
    public static function forLimiters(array $names): self
    {
        return new self(sprintf('Failed to clear the cache pool of rate limiter(s): %s', implode(', ', $names)));
    }
}
