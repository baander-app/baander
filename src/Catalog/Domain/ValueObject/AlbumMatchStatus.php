<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

/**
 * Where an album stands in automatic MusicBrainz matching. Backed by the value stored in
 * `album_match_states.state`; an album with no row has nothing pending.
 */
enum AlbumMatchStatus: string
{
    /** A match is wanted and waits for the matcher, from its due time on. */
    case Requested = 'requested';

    /** The matcher found no confident match, so an admin picks one. */
    case NeedsReview = 'needs_review';
}
