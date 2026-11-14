<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query\MusicBrainzMatch;

/**
 * A field of an album, song or artist that a MusicBrainz match can set.
 *
 * Where the item can lock the field, the value is the name it locks it by. Artist credits are not
 * a field: a comparison lists them in credit rows of their own.
 */
enum MatchField: string
{
    /** The MusicBrainz ID: an album's release group, a song's recording, an artist's own. */
    case Mbid = 'mbid';
    /** The MusicBrainz release (edition) an album matched. */
    case ReleaseMbid = 'releaseMbid';
    case Title = 'title';
    case Year = 'year';
    case Label = 'label';
    case CatalogNumber = 'catalogNumber';
    case Barcode = 'barcode';
    case Country = 'country';
    case Disambiguation = 'disambiguation';
    /** An album's genre names. */
    case Genres = 'genres';
    /** An album's cover image, which a match takes from the release's front cover. */
    case Cover = 'cover';
    case Track = 'track';
    case Disc = 'disc';
    case Name = 'name';
    case SortName = 'sortName';
    /** An artist's type, such as "Person" or "Group". */
    case Type = 'type';
    case LifeSpanBegin = 'lifeSpanBegin';
    case LifeSpanEnd = 'lifeSpanEnd';

    /** Whether the field holds a MusicBrainz ID, which a comparison ticks even over a different value. */
    public function isIdentifier(): bool
    {
        return $this === self::Mbid || $this === self::ReleaseMbid;
    }
}
