<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/** No limiter verdict is available; storage failure must not grant a fresh budget. */
final class RateLimiterUnavailableException extends ServiceUnavailableException
{
    public function __construct()
    {
        parent::__construct('Rate limiting is temporarily unavailable.');
    }
}
