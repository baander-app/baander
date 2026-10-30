<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

enum LiveScopeKind: string
{
    case User = 'user';
    case Library = 'library';
    case Playlist = 'playlist';
    case Admin = 'admin';
    case Global = 'global';
}
