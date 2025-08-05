<?php

namespace App\Models;

use App\Extensions\EnumExt;

enum AlbumType: string
{
    use EnumExt;

    case Studio      = 'studio';
    case Live        = 'live';
    case Compilation = 'compilation';
    case Soundtrack  = 'soundtrack';
    case Remix       = 'remix';
    case EP          = 'ep';
    case Single      = 'single';
    case Demo        = 'demo';
    case Mixtape     = 'mixtape';
    case Bootleg     = 'bootleg';
    case Interview   = 'interview';
    case Audiobook   = 'audiobook';
    case SpokenWord  = 'spoken_word';
    case Other       = 'other';
}