<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Asks for automatic MusicBrainz matches of albums through the album match queue.
 *
 * An album is requested only when a match has something to do: it has no release yet, or it is
 * matched and holds a song no match has compared yet. An album marked for review whose song
 * count is still the one the matcher checked is not requested again. Call it only after the
 * album's songs are committed.
 */
interface AlbumMatchRequestInterface
{
    /**
     * Request matches for albums an import or rescan touched, while automatic matching is on; does
     * nothing while it is off. The requests fall due after a settling delay, so that the album's
     * other discs land first.
     */
    public function requestAfterImport(Uuid ...$albumIds): void;

    /** Request an album's match for an admin, whatever the automatic matching switch says; it falls due at once. */
    public function requestForAdmin(Uuid $albumId): void;
}
