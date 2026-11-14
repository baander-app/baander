<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

/**
 * A part of a song that a MusicBrainz match can set, as the song records it once the match set it.
 *
 * Backed by the name stored in the song's list of matched fields. `Credits` stands for the song's
 * artist credits as a whole.
 */
enum MatchedSongField: string
{
    case Title = 'title';
    case Track = 'track';
    case Disc = 'disc';
    case Year = 'year';
    case Credits = 'credits';
}
