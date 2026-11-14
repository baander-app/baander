<?php

declare(strict_types=1);

namespace App\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\DTO\BulkFetchLyricsResult;
use App\Lyrics\Application\DTO\LyricsFetchOutcome;
use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Lyrics\Application\Service\LyricsLookupRequirements;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
use App\Shared\Application\Port\CurrentJobInterface;
use App\Shared\Application\Port\JobCancellationCheckpointInterface;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Handles BulkFetchLyricsCommand, which the admin bulk fetch, `app:lyrics:fetch` and
 * admin-created schedules dispatch.
 *
 * Walks song IDs and queues one FetchLyricsCommand for each song without lyrics, up to
 * the limit, on the durable async transport that the workers consume. The fetches are
 * spaced by the command's delay through DelayStamp, so they reach LRCLIB at that pace
 * while the handler itself returns as soon as they are queued. A song without a primary
 * artist credit, or whose length is missing or not positive, cannot be looked up: the walk
 * counts it per cause instead of queueing it, and it does not count toward the limit.
 *
 * The run keeps a tally beside its queued marks: the walk's skipped counts, recorded with its
 * job when the walk finishes, also when it queued nothing, and the outcome of each fetch,
 * which FetchLyricsHandler counts. The tally lasts as long as the run's other records, and the
 * run whose walk finished last is the one the lyrics status reports.
 *
 * Each queued song stays marked until its fetch has run, so a run that overlaps an earlier
 * one, such as a manual run during a scheduled one, skips the songs already queued. The
 * fetches name their run; when the job is cancelled while it queues, the run is recorded
 * cancelled until after its last fetch is due, and the fetches it already queued are skipped.
 * A run that finishes having queued fetches is recorded against its job for as long, so
 * cancelling the finished job in the job monitor cancels the run the same way.
 */
#[AsMessageHandler]
final class BulkFetchLyricsHandler
{
    private const int BATCH_SIZE = 50;

    /**
     * How long a mark outlives the moment its fetch is due, in seconds. It covers workers
     * that fall behind or are down for a while; a fetch that never runs releases its song
     * once this passes.
     */
    private const int MARK_GRACE_SECONDS = 86_400;

    public function __construct(
        private readonly SongLookupInterface $songs,
        private readonly LyricsRepositoryInterface $lyricsRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly JobCancellationCheckpointInterface $cancellation,
        private readonly QueuedLyricsFetchesInterface $queuedFetches,
        private readonly CurrentJobInterface $currentJob,
    ) {
    }

    /**
     * @return BulkFetchLyricsResult the number of songs queued for fetching, and of those skipped per cause
     *
     * @throws InvalidInputException for a limit below 1 or a negative delay
     * @throws JobCancelledException when the job is cancelled; the fetches queued so far stay queued and are skipped when due
     */
    public function __invoke(BulkFetchLyricsCommand $command): BulkFetchLyricsResult
    {
        $limit = $command->getLimit();
        $delayMs = $command->getDelayMs() ?? BulkFetchLyricsCommand::DEFAULT_DELAY_MS;
        if ($limit !== null && $limit < 1) {
            throw new InvalidInputException('The limit must be at least 1.', ['limit' => $limit]);
        }
        if ($delayMs < 0) {
            throw new InvalidInputException('The delay must not be negative.', ['delayMs' => $delayMs]);
        }

        $runId = Uuid::v7();
        $queued = 0;
        $skipped = [LyricsFetchOutcome::NoArtist->value => 0, LyricsFetchOutcome::NoLength->value => 0];
        $after = null;
        $this->queuedFetches->startRun($runId, self::markTtl(0));

        try {
            while ($limit === null || $queued < $limit) {
                $remaining = $limit !== null ? min(self::BATCH_SIZE, $limit - $queued) : self::BATCH_SIZE;
                $songIds = $this->songs->songIdsAfter($after, $remaining);
                // A page's signatures come from one query that loads no song entities.
                $signatures = $this->songs->findLyricSignatures($songIds);

                foreach ($songIds as $songId) {
                    if ($limit !== null && $queued >= $limit) {
                        break 2;
                    }

                    // A cancelled job stops before its next song.
                    $this->cancellation->check();

                    if ($this->lyricsRepository->findBySongId($songId) !== null) {
                        continue;
                    }

                    // A song deleted since its page was read is neither queued nor counted.
                    $signature = $signatures[$songId->toString()] ?? null;
                    if ($signature === null) {
                        continue;
                    }
                    $notAttempted = LyricsLookupRequirements::unmetBy($signature);
                    if ($notAttempted !== null) {
                        ++$skipped[$notAttempted->value];
                        continue;
                    }

                    $delay = $queued * $delayMs;
                    // Already queued by an overlapping run, whose fetch is still to come.
                    if (!$this->queuedFetches->markQueued($songId, self::markTtl($delay))) {
                        continue;
                    }

                    $this->bus->dispatch(new FetchLyricsCommand($songId, $runId), [
                        new TransportNamesStamp(['async']),
                        new DelayStamp($delay),
                    ]);
                    ++$queued;
                }

                if (count($songIds) < $remaining) {
                    break;
                }
                $after = $songIds[array_key_last($songIds)];
            }
        } catch (JobCancelledException $cancelled) {
            if ($queued > 0) {
                $this->queuedFetches->markRunCancelled($runId, self::markTtl(($queued - 1) * $delayMs));
            }

            throw $cancelled;
        }

        $result = new BulkFetchLyricsResult(
            $queued,
            $skipped[LyricsFetchOutcome::NoArtist->value],
            $skipped[LyricsFetchOutcome::NoLength->value],
        );
        $jobId = $this->currentJob->currentJobId();
        $ttl = self::markTtl(max(0, $queued - 1) * $delayMs);
        $this->queuedFetches->finishRun($runId, $result, $jobId, $ttl);
        // Only a run with fetches still to come gives its finished job something to cancel.
        if ($queued > 0 && $jobId !== null) {
            $this->queuedFetches->recordJobRun($jobId, $runId, $ttl);
        }

        $this->logger->info('Bulk lyrics fetch queued', [
            'queued' => $queued,
            'skipped_no_artist' => $result->skippedNoArtist,
            'skipped_no_length' => $result->skippedNoLength,
            'delay_ms' => $delayMs,
        ]);

        return $result;
    }

    /** Seconds from now until a day after a fetch delayed by the given milliseconds is due. */
    private static function markTtl(int $delayMs): int
    {
        return (int) ceil($delayMs / 1000) + self::MARK_GRACE_SECONDS;
    }
}
