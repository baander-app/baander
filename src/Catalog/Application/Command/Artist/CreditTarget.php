<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\Artist;

/**
 * What an artist credit names: a song or an album, identified by its internal UUID.
 */
enum CreditTarget: string
{
    case Song = 'song';
    case Album = 'album';

    /** The target's name as messages print it, such as `Song "…" not found.` */
    public function label(): string
    {
        return match ($this) {
            self::Song => 'Song',
            self::Album => 'Album',
        };
    }
}
