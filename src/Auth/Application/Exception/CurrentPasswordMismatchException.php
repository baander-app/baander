<?php

declare(strict_types=1);

namespace App\Auth\Application\Exception;

use RuntimeException;

/** A password change named a current password that does not match the account's. */
final class CurrentPasswordMismatchException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Current password is incorrect.');
    }
}
