<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/**
 * A password reset token cannot be redeemed. One message covers an unknown, expired or
 * already used token, so the response does not reveal which applied.
 */
final class PasswordResetException extends RuntimeException
{
    public static function invalidToken(): self
    {
        return new self('Invalid or expired password reset token.');
    }
}
