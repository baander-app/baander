<?php

namespace App\Auth;

use App\Extensions\EnumExt;

enum Role: string
{
    use EnumExt;

    case Admin = 'admin';
    case User = 'user';
}
