<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * The state every producer of artist syncs shares: the artists whose sync is queued, the artists
 * MusicBrainz answered about by ID, the runs of admin syncs a cancel reaches, and one due time
 * that spaces the syncs of every producer. Every entry expires on its own; a lost entry costs a
 * request, never a wrong write.
 */
interface QueuedArtistSyncsInterface
{
    /**
     * Marks the artist queued and reserves its sync's due time, in one step, unless the artist is
     * queued already or MusicBrainz answered about this MusicBrainz ID while that answer counts.
     * The sync falls due $spacingMs after the latest sync reserved before it, or now when that one
     * is due already. The mark lasts until $graceSeconds after the due time.
     *
     * @return int|null milliseconds from now until the sync is due; null when nothing was reserved
     */
    public function reserve(Uuid $artistId, string $musicbrainzId, int $spacingMs, int $graceSeconds): ?int;

    /**
     * Reserves a later due time for an artist whose sync is queued again: $spacingMs after the
     * latest sync reserved, and no sooner than $pauseMs from now. The artist's mark is renewed
     * until $graceSeconds after that time.
     *
     * @return int milliseconds from now until the sync is due
     */
    public function reserveAgain(Uuid $artistId, int $pauseMs, int $spacingMs, int $graceSeconds): int;

    /** Removes the artist's mark, once its sync has run. */
    public function clearQueued(Uuid $artistId): void;

    /** Records for the given time that MusicBrainz answered about the artist's MusicBrainz ID. */
    public function markAnswered(Uuid $artistId, string $musicbrainzId, int $ttlSeconds): void;

    public function isAnswered(Uuid $artistId, string $musicbrainzId): bool;

    /**
     * Records for the given time that the admin sync's run was cancelled, and sets the shared due
     * time back to now: the run's syncs still queued make no request.
     */
    public function markRunCancelled(Uuid $runId, int $ttlSeconds): void;

    public function isRunCancelled(Uuid $runId): bool;

    /**
     * Records for the given time that the job-monitor job queued the run's syncs, so cancelling the
     * job after it finished cancels the run (QueuedJobWorkInterface).
     */
    public function recordJobRun(string $jobId, Uuid $runId, int $ttlSeconds): void;
}
