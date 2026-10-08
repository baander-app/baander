<?php

declare(strict_types=1);

namespace App\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Application\Exception\InvalidInputException;
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
 */
#[AsMessageHandler]
final class BulkFetchLyricsHandler
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly SongLookupInterface $songs,
        private readonly LyricsRepositoryInterface $lyricsRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int the number of songs queued for fetching
     *
     * @throws InvalidInputException for a limit below 1 or a negative delay
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

        $queued = 0;
        $after = null;

        while ($limit === null || $queued < $limit) {
            $remaining = $limit !== null ? min(self::BATCH_SIZE, $limit - $queued) : self::BATCH_SIZE;
            $songIds = $this->songs->songIdsAfter($after, $remaining);

            foreach ($songIds as $songId) {
                if ($limit !== null && $queued >= $limit) {
                    break 2;
                }

                if ($this->lyricsRepository->findBySongId($songId) !== null) {
                    continue;
                }

                $this->bus->dispatch(new FetchLyricsCommand($songId), [
                    new TransportNamesStamp(['async']),
                    new DelayStamp($queued * $delayMs),
                ]);
                ++$queued;
            }

            if (count($songIds) < $remaining) {
                break;
            }
            $after = $songIds[array_key_last($songIds)];
        }

        $this->logger->info('Bulk lyrics fetch queued', [
            'queued' => $queued,
            'delay_ms' => $delayMs,
        ]);

        return $queued;
    }
}
