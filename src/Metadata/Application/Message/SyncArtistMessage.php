<?php

declare(strict_types=1);

namespace App\Metadata\Application\Message;

use App\Shared\Domain\Model\Uuid;

/**
 * The fill of one artist's empty details from MusicBrainz, by the MusicBrainz ID the artist holds.
 * Catalog handles it.
 */
final readonly class SyncArtistMessage
{
    /**
     * @param Uuid|null $runId   the admin sync's run that queued the fill; null for a fill a match or an import queued
     * @param int       $outages the MusicBrainz outages this fill met in a row before it was queued again
     */
    public function __construct(
        public Uuid $artistId,
        public ?Uuid $runId = null,
        public int $outages = 0,
    ) {
    }
}
