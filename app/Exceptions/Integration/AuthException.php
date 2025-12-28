<?php

namespace App\Exceptions\Integration;

use Exception;

class AuthException extends Exception
{
    public static function throwMissingCredentials(): void
    {
        throw new self('Missing credentials.');
    }
}
