<?php

declare(strict_types=1);

namespace App\Catalog\Application\MessageHandler;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Application\Port\QueuedArtistSyncsInterface;
use App\Catalog\Application\Service\ArtistSyncQueue;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\CurrentJobInterface;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The part of an administrator's metadata sync that queues the fill of every artist with a
 * MusicBrainz ID and an empty detail, once for the catalog however many libraries credit it.
 * The syncs fall due at the shared pace; an artist whose sync is queued already, or that
 * MusicBrainz answered about the same ID within 30 days, is skipped.
 *
 * The syncs name their run. When the job is cancelled while it queues, it stops before its next
 * artist and the run is recorded cancelled; a run that finishes having queued syncs is recorded
 * against its job, so cancelling the finished job in the job monitor cancels the run the same
 * way. The syncs of a cancelled run end without a request.
 */
final readonly class QueueArtistSyncsHandler
{
    private const int PAGE_SIZE = 500;

    public function __construct(
        private ArtistPortInterface $artists,
        private ArtistSyncQueue $queue,
        private QueuedArtistSyncsInterface $marks,
        private JobCancellationCheckpointInterface $cancellation,
        private CurrentJobInterface $currentJob,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int the artist syncs queued
     *
     * @throws JobCancelledException when the job is cancelled; the syncs queued so far end without a request
     */
    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(SyncArtistsMessage $message): int
    {
        $runId = Uuid::v7();
        $queued = 0;
        $latestDelayMs = 0;
        $after = null;
        try {
            do {
                $page = $this->artists->identifiedWithMissingDetailsAfter($after, self::PAGE_SIZE);
                foreach ($page as $artistId => $musicbrainzId) {
                    // A cancelled job stops before its next artist.
                    $this->cancellation->check();
                    $delayMs = $this->queue->queue(Uuid::fromString($artistId), $musicbrainzId, $runId);
                    if ($delayMs !== null) {
                        ++$queued;
                        $latestDelayMs = max($latestDelayMs, $delayMs);
                    }
                }
                $last = array_key_last($page);
                $after = $last === null ? null : Uuid::fromString((string) $last);
            } while (count($page) === self::PAGE_SIZE);
        } catch (JobCancelledException $cancelled) {
            if ($queued > 0) {
                $this->marks->markRunCancelled($runId, self::recordTtl($latestDelayMs));
            }

            throw $cancelled;
        }

        $jobId = $this->currentJob->currentJobId();
        // Only a run with syncs still to come gives its finished job something to cancel.
        if ($queued > 0 && $jobId !== null) {
            $this->marks->recordJobRun($jobId, $runId, self::recordTtl($latestDelayMs));
        }
        $this->logger->info('Artist syncs queued', ['queued' => $queued, 'run_id' => $runId->toString(), 'last_due_in_ms' => $latestDelayMs]);

        return $queued;
    }

    /** Seconds from now until the run's records may go: as long as the mark of its last sync lasts. */
    private static function recordTtl(int $delayMs): int
    {
        return (int) ceil($delayMs / 1000) + ArtistSyncQueue::MARK_GRACE_SECONDS;
    }
}
