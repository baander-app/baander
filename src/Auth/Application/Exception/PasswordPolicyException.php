<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/** A new password breaks the password policy (PasswordPolicy::MIN_LENGTH and MAX_LENGTH). */
final class PasswordPolicyException extends RuntimeException
{
    public static function length(int $min, int $max): self
    {
        return new self(sprintf('Password must be between %d and %d characters.', $min, $max));
    }
}
