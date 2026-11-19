<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/** An authenticated client has used up its request allowance; it may retry after the given delay. */
final class OAuthClientRateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many requests. Please try again later.');
    }
}
