<?php

namespace App\Modules\Auth\Webauthn\Exceptions;

use Exception;

class InvalidPasskeyOptions extends Exception
{
    public static function invalidJson(): self
    {
        return new self('The given passkey options should be formatted as json. Please check the format and try again.');
    }
}