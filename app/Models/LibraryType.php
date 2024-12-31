<?php

namespace App\Models;

use App\Support\EnumExtensions;

enum LibraryType: string
{
    use EnumExtensions;

    case Music = 'music';
    case Podcast = 'podcast';
    case Audiobook = 'audiobook';
    case Movie = 'movie';
    case TvShow = 'tv_show';
}
