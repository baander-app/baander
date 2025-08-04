<?php

namespace App\Modules\Auth\Webauthn\Exceptions;

use Exception;

class CurrentCountExceededSource extends Exception
{
    public static function throw(int $current, int $source): self
    {
        return new self("The current count has exceeded the source count. Possible cloned passkey! Current: $current Source: $source");
    }
}