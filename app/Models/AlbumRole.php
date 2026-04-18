<?php

namespace App\Models;

use App\Primitives\Traits\EnumExtensions;

enum AlbumRole: string
{
    use EnumExtensions;

    case Primary = 'primary';
    case Featured = 'featured';
    case Producer = 'producer';
    case Writer = 'writer';
}