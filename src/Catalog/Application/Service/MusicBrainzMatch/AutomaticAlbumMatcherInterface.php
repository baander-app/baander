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
     * A matched album (one with a release ID) pairs only its songs created after its last apply
     * against its stored release, without a search: a song that pairs takes its recording ID and
     * credits from that release, and one that pairs with nothing stays as it is. Such an album is
     * matched even when no song pairs, and is marked for review only when MusicBrainz no longer has
     * its stored release.
     *
     * Any other album is searched on MusicBrainz, decided by MatchConfidence and, when one candidate
     * is confident, applied in fill-only automatic mode: IDs, artist credits, artist merges and
     * removals, empty fields and a missing cover, never a field that holds a value or is locked.
     *
     * A matched outcome means the apply committed and moved the album's match time to the apply, so
     * no later import requests a match for the songs the album holds now, or that an admin matched
     * the album meanwhile. The match state is left to the queue, which removes the request it
     * claimed. Every other outcome writes nothing. A MusicBrainz outage, throttle or refusal, and an
     * apply that met changed rows or a deadlock, are an unavailable outcome, never an exception; a
     * needs-review outcome carries the reason, the best score and the album's song count the
     * matcher checked.
     */
    public function match(Uuid $albumId): AutomaticMatchOutcome;
}
