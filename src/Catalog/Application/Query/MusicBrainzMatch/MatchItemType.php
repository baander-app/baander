<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

/**
 * The kind of library item an admin matches with MusicBrainz, and what it matches: an album
 * matches a release (one edition), a song a recording, and an artist an artist.
 */
enum MatchItemType: string
{
    case Album = 'album';
    case Song = 'song';
    case Artist = 'artist';

    /** How the item names itself in messages, such as `Album "…" not found.` */
    public function label(): string
    {
        return match ($this) {
            self::Album => 'Album',
            self::Song => 'Song',
            self::Artist => 'Artist',
        };
    }
}
