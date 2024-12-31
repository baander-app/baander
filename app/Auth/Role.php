<?php

namespace App\Auth;

use App\Support\EnumExtensions;

enum Role: string
{
    use EnumExtensions;

    case Admin = 'admin';
    case User = 'user';
}
