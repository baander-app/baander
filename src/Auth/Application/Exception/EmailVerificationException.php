<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * An email verification token cannot be redeemed. One message covers an unknown, expired or
 * already used token and a token for an address the account no longer has, so the response
 * does not reveal which applied.
 */
final class EmailVerificationException extends RuntimeException
{
    public static function invalid(): self
    {
        return new self('Invalid or expired verification token.');
    }
}
