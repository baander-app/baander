<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service\MusicBrainzMatch;

use App\Shared\Domain\Model\Uuid;

/**
 * Matches one album to MusicBrainz without an admin, as the album match queue does for each
 * request it takes.
 */
interface AutomaticAlbumMatcherInterface
{
    /**
     * Match one album without an admin.
     *
     * A matched album (one with a release ID) pairs only its songs no match has compared yet
     * against its stored release, without a search: a song that pairs takes its recording ID and
     * credits from that release, and one that pairs with nothing stays as it is. Such an album is
     * matched even when no song pairs, and is marked for review only when MusicBrainz no longer has
     * its stored release.
     *
     * Any other album is searched on MusicBrainz, decided by MatchConfidence and, when one candidate
     * is confident, applied in fill-only automatic mode: IDs, artist credits, artist merges and
     * removals, empty fields and a missing cover, never a field that holds a value or is locked.
     *
     * A matched outcome means the apply committed and marked every song it compared, so no later
     * import requests a match for those songs, or that an admin matched the album meanwhile. The
     * match state is left to the queue, which removes the request it claimed. Every other outcome
     * writes nothing, and none of them is an exception: a MusicBrainz outage or throttle is
     * unavailable; an apply that met changed rows or a deadlock, and a request MusicBrainz refused,
     * retry this album later after the outcome's delay; a needs-review outcome carries the reason,
     * the best score and the album's song count the matcher checked.
     */
    public function match(Uuid $albumId): AutomaticMatchOutcome;
}
