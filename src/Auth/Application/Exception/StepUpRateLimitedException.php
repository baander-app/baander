<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/** The account has used up its step-up attempts for the current window. */
final class StepUpRateLimitedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many attempts. Try again later.');
    }
}
