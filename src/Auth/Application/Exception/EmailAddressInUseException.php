<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/** An email change named an address that another account already uses. */
final class EmailAddressInUseException extends RuntimeException
{
    public static function create(): self
    {
        return new self('This email address is already in use.');
    }
}
