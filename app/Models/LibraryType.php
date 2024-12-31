<?php

namespace App\Models;

use App\Extensions\EnumExt;

enum LibraryType: string
{
    use EnumExt;

    case Music = 'music';
    case Podcast = 'podcast';
    case Audiobook = 'audiobook';
    case Movie = 'movie';
    case TvShow = 'tv_show';
}
