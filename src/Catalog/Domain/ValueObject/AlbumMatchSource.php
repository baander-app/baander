<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

/**
 * What asked for an album's MusicBrainz match. Backed by the value stored in
 * `album_match_states.source`.
 */
enum AlbumMatchSource: string
{
    /** An import or rescan touched the album while automatic matching was on. */
    case Ingest = 'ingest';

    /** An admin asked, through a library or genre sync. */
    case Admin = 'admin';
}
