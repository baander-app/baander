<?php

namespace App\Models;

use App\Extensions\EnumExt;

enum AlbumRole: string
{
    use EnumExt;

    case Primary = 'primary';
    case Featured = 'featured';
    case Producer = 'producer';
    case Writer = 'writer';
}