<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Catalog\Application\Port\QueuedArtistSyncsInterface;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Shared\Domain\Model\Uuid;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Queues artist syncs for every producer: an admin sync, and a match or an import that gives an
 * artist its MusicBrainz ID. An artist whose sync is queued is not queued again, nor one
 * MusicBrainz answered about the same ID within 30 days, and the syncs fall due at least two
 * seconds apart whoever queued them, so the album match queue keeps every other turn of the
 * shared MusicBrainz limit and a backlog waits in the transport rather than in a worker.
 */
final readonly class ArtistSyncQueue
{
    /** Milliseconds between two artist syncs falling due: one turn of the shared limit and the request's own time. */
    public const int SPACING_MS = 2_000;

    /**
     * How long a queued mark outlives the moment its sync is due, in seconds. It covers workers
     * that fall behind or are down for a while, and a sync that meets outages and waits for them.
     */
    public const int MARK_GRACE_SECONDS = 86_400;

    /** How long MusicBrainz's answer about an artist's ID, a record or a 404, keeps the artist from being asked again. */
    public const int ANSWER_TTL_SECONDS = 30 * 86_400;

    public function __construct(
        private QueuedArtistSyncsInterface $marks,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Queues the artist's sync at the shared pace, unless it is queued already or MusicBrainz
     * answered about this MusicBrainz ID within 30 days.
     *
     * @param Uuid|null $runId the admin sync's run that queues it; null for a match or an import
     *
     * @return int|null milliseconds until the sync is due; null when it was not queued
     */
    public function queue(Uuid $artistId, string $musicbrainzId, ?Uuid $runId = null): ?int
    {
        $delayMs = $this->marks->reserve($artistId, $musicbrainzId, self::SPACING_MS, self::MARK_GRACE_SECONDS);
        if ($delayMs !== null) {
            $this->bus->dispatch(new SyncArtistMessage($artistId, $runId), [new DelayStamp($delayMs)]);
        }

        return $delayMs;
    }

    /**
     * Queues a sync that met a MusicBrainz outage again, no sooner than the pause and at the shared
     * pace, with its run, one more outage counted, and the artist's mark renewed until then.
     *
     * @return int milliseconds until the sync is due
     */
    public function queueAfterOutage(SyncArtistMessage $sync, int $pauseMs): int
    {
        $delayMs = $this->marks->reserveAgain($sync->artistId, $pauseMs, self::SPACING_MS, self::MARK_GRACE_SECONDS);
        $this->bus->dispatch(new SyncArtistMessage($sync->artistId, $sync->runId, $sync->outages + 1), [new DelayStamp($delayMs)]);

        return $delayMs;
    }
}
