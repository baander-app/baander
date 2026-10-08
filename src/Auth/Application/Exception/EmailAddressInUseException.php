<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use App\Shared\Application\Exception\ConflictException;

/** An email change named an address that another account already uses; HTTP answers 409 and console commands fail. */
final class EmailAddressInUseException extends ConflictException
{
    public static function create(): self
    {
        return new self('This email address is already in use.');
    }
}
