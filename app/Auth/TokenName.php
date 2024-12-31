<?php

namespace App\Auth;

use App\Extensions\EnumExt;

enum TokenName: string
{
    use EnumExt;

    case Access = 'access_token';
    case Refresh = 'refresh_token';
    case Stream  = 'stream_token';

    public function camelCaseValue(): string
    {
        return self::toCamelCase($this->value);
    }
}
