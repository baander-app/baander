<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

/** How matching one album without an admin ended. */
enum AutomaticMatchOutcomeKind: string
{
    /**
     * The album is matched: an unmatched album took a confident candidate, a matched album paired
     * its new songs against its stored release, or an admin matched the album meanwhile.
     */
    case Matched = 'matched';

    /** No candidate was confident; nothing was written, and an admin picks the match. */
    case NeedsReview = 'needs_review';

    /**
     * Nothing was written and the album is to be matched again later: MusicBrainz or the Cover Art
     * Archive gave no usable answer, refused the request or throttled it, or the apply met rows
     * that changed while it waited for its locks, or a deadlock.
     */
    case Unavailable = 'unavailable';

    /** The album no longer exists. */
    case AlbumGone = 'album_gone';
}
