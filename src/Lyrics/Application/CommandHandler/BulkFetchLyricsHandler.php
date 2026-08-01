<?php

declare(strict_types=1);

namespace App\Lyrics\Application\CommandHandler;

use App\Catalog\Application\Port\SongPortInterface;
use App\Lyrics\Application\Command\BulkFetchLyricsCommand;
use App\Lyrics\Application\Command\FetchLyricsCommand;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Domain\Model\Cursor;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Infrastructure\Swoole\Async;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles BulkFetchLyricsCommand.
 *
 * Iterates songs and dispatches individual FetchLyricsCommand instances
 * for those without lyrics. Applies a configurable delay between dispatches
 * for respectful crawling.
 */
#[AsMessageHandler]
final class BulkFetchLyricsHandler
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly SongPortInterface $songPort,
        private readonly LyricsRepositoryInterface $lyricsRepository,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(BulkFetchLyricsCommand $command): int
    {
        $limit = $command->getLimit();
        $delayMs = $command->getDelayMs() ?? 500;
        $dispatched = 0;
        $cursor = null;

        while (true) {
            if ($limit !== null && $dispatched >= $limit) {
                break;
            }

            $remaining = $limit !== null ? min(self::BATCH_SIZE, $limit - $dispatched) : self::BATCH_SIZE;
            $options = SearchOptions::create('*', $remaining, 0)->withCursor($cursor);
            $page = $this->songPort->searchWithCursor($options);

            if ($page->getItems() === []) {
                break;
            }

            foreach ($page->getItems() as $song) {
                if ($limit !== null && $dispatched >= $limit) {
                    break 2;
                }

                $existing = $this->lyricsRepository->findBySongId($song->getId());
                if ($existing !== null) {
                    continue;
                }

                try {
                    $this->bus->dispatch(new FetchLyricsCommand($song->getId()));
                    ++$dispatched;

                    if ($delayMs > 0) {
                        Async::sleep($delayMs / 1000);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to dispatch lyrics fetch for song', [
                        'song_id' => $song->getId()->toString(),
                        'error' => $e->getMessage(),
                        'dispatched' => $dispatched,
                    ]);
                }
            }

            $cursor = $page->getNextCursor();
            if ($cursor === null) {
                break;
            }
        }

        $this->logger->info('Bulk lyrics fetch completed', [
            'dispatched' => $dispatched,
        ]);

        return $dispatched;
    }
}
