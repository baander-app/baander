<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject;

/**
 * Why the matcher left an album for an admin instead of matching it. Backed by the value stored in
 * `album_match_states.reason`.
 */
enum AlbumMatchReviewReason: string
{
    /** MusicBrainz returned no release for the album's title and artist. */
    case NoCandidates = 'no_candidates';

    /** The best release fits the album too poorly to be trusted. */
    case LowScore = 'low_score';

    /** Another release fits almost as well as the best one, and their tracks disagree. */
    case CloseRunnerUp = 'close_runner_up';

    /** The best release has another number of tracks than the album. */
    case TrackCountDiffers = 'track_count_differs';

    /** A track of the album pairs with no track of the best release within the length tolerance. */
    case TrackLengthsDiffer = 'track_lengths_differ';
}
