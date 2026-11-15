<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * Queues the fill of the artists a match apply or an import gave a MusicBrainz ID, once the writer
 * that identified them committed. An artist whose fill is queued already, or that MusicBrainz
 * answered about the same ID within 30 days, is not queued again. Neither method throws: a failure
 * to queue is logged with the artists, and a later metadata sync from the admin panel queues them.
 */
interface ArtistSyncRequestInterface
{
    /** Queues the fills an import batch's artists need, after the batch committed, while automatic matching is on. */
    public function requestAfterImport(IdentifiedArtist ...$artists): void;

    /** Queues the fills a match apply's artists need, after the apply committed, whether automatic matching is on or off. */
    public function requestAfterMatch(IdentifiedArtist ...$artists): void;
}
