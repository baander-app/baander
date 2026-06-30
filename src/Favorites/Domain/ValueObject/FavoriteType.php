<?php

declare(strict_types=1);

namespace App\Favorites\Domain\ValueObject;

enum FavoriteType: string
{
    case Song = 'song';
    case Album = 'album';
    case Artist = 'artist';

    public function label(): string
    {
        return match ($this) {
            self::Song => 'Song',
            self::Album => 'Album',
            self::Artist => 'Artist',
        };
    }
}
