<?php

namespace App\Models;

enum LibraryType: string
{
    case Music = 'music';
    case Podcast = 'podcast';
    case Audiobook = 'audiobook';
    case Movie = 'movie';
    case TvShow = 'tv_show';
}
