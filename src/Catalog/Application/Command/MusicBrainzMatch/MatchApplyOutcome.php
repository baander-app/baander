<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command\MusicBrainzMatch;

/**
 * How an apply ended. A manual apply that does not apply answers with an exception instead; an
 * automatic apply reports every outcome here, so the album match queue can act on it.
 */
enum MatchApplyOutcome: string
{
    /** The match committed. An automatic apply commits without a cover the Cover Art Archive refused. */
    case Applied = 'applied';

    /** The album no longer exists; nothing was written. */
    case AlbumGone = 'album_gone';

    /** The album holds another release ID than the matcher read; nothing was written. */
    case AlbumMatchedMeanwhile = 'album_matched';

    /**
     * The album, its songs or their artists changed since the matcher read them or while the apply
     * waited for its locks; nothing was written.
     */
    case Stale = 'match_stale';

    /** The Cover Art Archive did not answer or throttled the request for the cover; nothing was written. */
    case Unavailable = 'musicbrainz_unavailable';
}
