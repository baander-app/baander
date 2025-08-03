<?php

namespace App\Http;

use App\Extensions\EnumExt;
use App\Modules\Auth\TokenBindingService;

enum HeaderExt : string
{
    use EnumExt;

    /**
     * This header is used for the token binding session id
     * @see TokenBindingService
     */
    case X_BAANDER_SESSION_ID = 'X-Baander-Session-Id';
}
