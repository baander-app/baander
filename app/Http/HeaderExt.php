<?php

namespace App\Http;

use App\Primitives\Traits\EnumExtensions;
use App\Modules\Auth\TokenBindingService;

enum HeaderExt : string
{
    use EnumExtensions;

    /**
     * This header is used for the token binding session id
     * @see TokenBindingService
     */
    case X_BAANDER_SESSION_ID = 'X-Baander-Session-Id';
}
