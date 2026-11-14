<?php

declare(strict_types=1);

namespace App\Lyrics\Application\DTO;

/** What a lyrics fetch for one song came to. */
enum LyricsFetchOutcome: string
{
    /** The song has lyrics, stored earlier or just fetched. */
    case Found = 'found';

    /** LRCLIB has no lyrics for the song, or the song no longer exists. */
    case NoMatch = 'no_match';

    /** Not attempted: the song has no primary artist credit, which a lookup needs. */
    case NoArtist = 'no_artist';

    /** Not attempted: the song's length, which a lookup needs, is missing or not positive. */
    case NoLength = 'no_length';

    /** LRCLIB refused the request with a client error other than 404, 408 or 429. */
    case Rejected = 'rejected';

    /** LRCLIB gave no usable answer; a later fetch may succeed. */
    case Unavailable = 'unavailable';
}
