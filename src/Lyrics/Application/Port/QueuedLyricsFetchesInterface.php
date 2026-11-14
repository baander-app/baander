<?php

declare(strict_types=1);

namespace App\Lyrics\Application\Port;

use App\Lyrics\Application\DTO\BulkFetchLyricsResult;
use App\Lyrics\Application\DTO\BulkLyricsRunTally;
use App\Lyrics\Application\DTO\LyricsFetchOutcome;
use App\Shared\Domain\Model\Uuid;

/**
 * Tracks the lyrics fetches that bulk runs queue with a delay, so that overlapping runs do
 * not queue a song twice and the fetches of a cancelled run are skipped, also when its job is
 * cancelled after it finished. It also keeps each run's tally of outcomes, which the lyrics
 * status reports for the latest run. Every entry expires on its own after its time to live,
 * so a fetch that never runs releases its song.
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

    /**
     * Opens the run's tally for the given time, before its walk queues the first fetch, so a
     * fetch that runs while the walk goes on is counted.
     */
    public function startRun(Uuid $runId, int $ttlSeconds): void;

    /**
     * Adds the finished walk to the run's tally with its job, keeps the tally for the given
     * time, and makes the run the latest one.
     */
    public function finishRun(Uuid $runId, BulkFetchLyricsResult $walk, ?string $jobId, int $ttlSeconds): void;

    /**
     * Counts the outcome of the run's fetch of the song once: a redelivered fetch of the same
     * song in the same run is not counted again. Nothing is counted once the run's tally has
     * expired, or for a run that was never started.
     */
    public function recordOutcome(Uuid $runId, Uuid $songId, LyricsFetchOutcome $outcome): void;

    /** The tally of the run that finished its walk last, or null once it has expired. */
    public function latestRun(): ?BulkLyricsRunTally;
}
