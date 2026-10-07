<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Application\Exception\PasswordPolicyException;

/**
 * The rules a new plain-text password must meet before it is hashed.
 *
 * Length counts characters (mb_strlen), not bytes, as the HTTP requests' Length constraints do,
 * so a password of eight multibyte characters is accepted and one of 255 is the longest.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 8;
    public const int MAX_LENGTH = 255;

    /** @throws PasswordPolicyException when the password is too short or too long */
    public static function assertAcceptable(string $plainPassword): void
    {
        $length = mb_strlen($plainPassword);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw PasswordPolicyException::length(self::MIN_LENGTH, self::MAX_LENGTH);
        }
    }
}
