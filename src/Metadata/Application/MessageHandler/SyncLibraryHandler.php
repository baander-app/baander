<?php

declare(strict_types=1);

namespace App\Metadata\Application\MessageHandler;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncLibraryHandler
{
    public function __construct(
        private readonly AlbumPortInterface $albumService,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'swoole_task')]
    public function __invoke(SyncLibraryMessage $message): void
    {
        $libraryId = $message->libraryId;
        $albums = $this->albumService->findByLibrary($libraryId);

        $this->logger->info('Dispatching sync for library albums', [
            'library_id' => $libraryId->toString(),
            'album_count' => count($albums),
            'force_update' => $message->forceUpdate,
            'include_songs' => $message->includeSongs,
        ]);

        foreach ($albums as $album) {
            $this->bus->dispatch(new SyncAlbumMessage(
                $album->getId(),
                $message->forceUpdate,
            ));

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
    }
}
