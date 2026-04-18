<?php

namespace App\Auth;

use App\Primitives\Traits\EnumExtensions;

enum Role: string
{
    use EnumExtensions;

    case Admin = 'admin';
    case User = 'user';
}
