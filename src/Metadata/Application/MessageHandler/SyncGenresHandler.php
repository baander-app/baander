<?php

declare(strict_types=1);

namespace App\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongPortInterface;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncGenresMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Shared\Domain\Model\SearchOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncGenresHandler
{
    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly SongPortInterface $songService,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(SyncGenresMessage $message): void
    {
        $totalAlbums = $this->albumService->count();

        $this->logger->info('Starting genre sync', [
            'total_albums' => $totalAlbums,
            'force_update' => $message->forceUpdate,
            'include_songs' => $message->includeSongs,
        ]);

        // Iterate all albums via search with empty query
        $offset = 0;
        $limit = 100;
        $dispatched = 0;

        while (true) {
            $options = SearchOptions::create(query: '', limit: $limit, offset: $offset);
            $result = $this->albumService->search($options);

            if ($result->isEmpty()) {
                break;
            }

            foreach ($result->getItems() as $album) {
                $this->bus->dispatch(new SyncAlbumMessage(
                    $album->getId(),
                    $message->forceUpdate,
                ));
                $dispatched++;

                if ($message->includeSongs) {
                    $albumWithSongs = $this->albumService->findWithSongs($album->getId());
                    if ($albumWithSongs !== null) {
                        [, $songList] = $albumWithSongs;
                        foreach ($songList as $song) {
                            $this->bus->dispatch(new SyncSongMessage(
                                $song->getId(),
                                $message->forceUpdate,
                            ));
                        }
                    }
                }
            }

            $offset += $limit;

            if (count($result->getItems()) < $limit) {
                break;
            }
        }

        $this->logger->info('Genre sync dispatch complete', [
            'albums_dispatched' => $dispatched,
        ]);
    }
}
