<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

final class EmailVerificationException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('Verification token is required.');
    }

    public static function invalid(): self
    {
        return new self('Invalid or expired verification token.');
    }

    public static function expired(): self
    {
        return new self('Verification token has expired.');
    }

    public static function alreadyUsed(): self
    {
        return new self('Verification token has already been used.');
    }
}
