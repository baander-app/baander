<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

use App\Shared\Domain\Model\Uuid;

/**
 * Tracks the lyrics fetches that bulk runs queue with a delay, so that overlapping runs do
 * not queue a song twice and the fetches of a cancelled run are skipped, also when its job is
 * cancelled after it finished. Every entry
 * expires on its own after its time to live, so a fetch that never runs releases its song.
 */
interface QueuedLyricsFetchesInterface
{
    /**
     * Marks the song as queued for the given time, unless it already is.
     *
     * @return bool false when the song is already marked; the existing mark is kept
     */
    public function markQueued(Uuid $songId, int $ttlSeconds): bool;

    /** Removes the song's mark, once its queued fetch has run. */
    public function clearQueued(Uuid $songId): void;

    /** Records for the given time that the bulk run was cancelled. */
    public function markRunCancelled(Uuid $runId, int $ttlSeconds): void;

    public function isRunCancelled(Uuid $runId): bool;

    /**
     * Records for the given time that the job-monitor job queued the bulk run's fetches, so
     * cancelling the job after it finished cancels the run (QueuedJobWorkInterface).
     */
    public function recordJobRun(string $jobId, Uuid $runId, int $ttlSeconds): void;
}
