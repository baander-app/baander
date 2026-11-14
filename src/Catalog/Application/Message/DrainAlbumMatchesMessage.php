<?php

declare(strict_types=1);

namespace App\Catalog\Application\Message;

/**
 * Asks the album match queue to match its oldest due album. The handler queues the next message
 * itself while requests wait, so the queue has one message in flight.
 */
final readonly class DrainAlbumMatchesMessage
{
    /**
     * @param int<0, max> $outages how many runs in a row found MusicBrainz unavailable; sets how long the next run waits
     */
    public function __construct(
        public int $outages = 0,
    ) {
    }
}
