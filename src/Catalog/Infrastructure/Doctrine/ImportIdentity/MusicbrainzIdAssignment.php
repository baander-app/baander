<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\ImportIdentity;

/** The outcome of giving an artist a MusicBrainz ID a metadata search found; the value names it in logs. */
enum MusicbrainzIdAssignment: string
{
    case Assigned = 'assigned';
    case MalformedId = 'malformed-id';
    case ArtistMissing = 'artist-missing';
    case ArtistHasId = 'artist-has-id';
    /** The artist's name changed between the read that chose the name lock and the read under it. */
    case ArtistRenamed = 'artist-renamed';
    case NameDiffers = 'name-differs';
    case HeldByAnotherArtist = 'held-by-another-artist';
}
