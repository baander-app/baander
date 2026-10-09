<?php

declare(strict_types=1);

namespace App\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Application\Port\QueuedLyricsFetchesInterface;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\JobCancelledException;
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
 * while the handler itself returns as soon as they are queued.
 *
 * Each queued song stays marked until its fetch has run, so a run that overlaps an earlier
 * one, such as a manual run during a scheduled one, skips the songs already queued. The
 * fetches name their run; when the job is cancelled while it queues, the run is recorded
 * cancelled until after its last fetch is due, and the fetches it already queued are skipped.
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
    ) {
    }

    /**
     * @return int the number of songs queued for fetching
     *
     * @throws InvalidInputException for a limit below 1 or a negative delay
     * @throws JobCancelledException when the job is cancelled; the fetches queued so far stay queued and are skipped when due
     */
    public function __invoke(BulkFetchLyricsCommand $command): int
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
        $after = null;

        try {
            while ($limit === null || $queued < $limit) {
                $remaining = $limit !== null ? min(self::BATCH_SIZE, $limit - $queued) : self::BATCH_SIZE;
                $songIds = $this->songs->songIdsAfter($after, $remaining);

                foreach ($songIds as $songId) {
                    if ($limit !== null && $queued >= $limit) {
                        break 2;
                    }

                    // A cancelled job stops before its next song.
                    $this->cancellation->check();

                    if ($this->lyricsRepository->findBySongId($songId) !== null) {
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

        $this->logger->info('Bulk lyrics fetch queued', [
            'queued' => $queued,
            'delay_ms' => $delayMs,
        ]);

        return $queued;
    }

    /** Seconds from now until a day after a fetch delayed by the given milliseconds is due. */
    private static function markTtl(int $delayMs): int
    {
        return (int) ceil($delayMs / 1000) + self::MARK_GRACE_SECONDS;
    }
}
