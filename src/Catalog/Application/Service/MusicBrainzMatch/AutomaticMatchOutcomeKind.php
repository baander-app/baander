<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

/** How matching one album without an admin ended. */
enum AutomaticMatchOutcomeKind: string
{
    /**
     * The album is matched: an unmatched album took a confident candidate, a matched album paired
     * its new songs against its stored release, or an admin matched the album meanwhile. A match
     * whose cover the Cover Art Archive refused is applied without the cover and counts as well.
     */
    case Matched = 'matched';

    /** No candidate was confident; nothing was written, and an admin picks the match. */
    case NeedsReview = 'needs_review';

    /**
     * Nothing was written, and this album alone is matched again after a delay: the apply met rows
     * that changed since the matcher read the album or while it waited for its locks, or a
     * deadlock, or MusicBrainz refused a request about the album. The other albums go on being
     * matched.
     */
    case RetryLater = 'retry_later';

    /**
     * Nothing was written because MusicBrainz or the Cover Art Archive gave no usable answer or
     * throttled the request, which holds for every album: matching pauses for all of them.
     */
    case Unavailable = 'unavailable';

    /** The provider was switched off and the unchanged request waits for recovery. */
    case Disabled = 'disabled';

    /** The album no longer exists. */
    case AlbumGone = 'album_gone';
}
