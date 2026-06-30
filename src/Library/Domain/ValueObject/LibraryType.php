<?php

declare(strict_types=1);

namespace App\Library\Domain\ValueObject;

enum LibraryType: string
{
    case Music = 'music';
    case Podcast = 'podcast';
    case Audiobook = 'audiobook';
    case Movie = 'movie';
    case TvShow = 'tv_show';
}
