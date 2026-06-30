<?php

declare(strict_types=1);

namespace App\Metadata\Application;

use App\Library\Application\Port\LibraryPortInterface;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncArtistMessage;
use App\Metadata\Application\Message\SyncGenresMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class MetadataSyncOrchestrator
{
    public function __construct(
        private readonly LibraryPortInterface $libraryService,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function syncLibrary(
        string $libraryId,
        bool $forceUpdate = false,
        bool $includeSongs = false,
        bool $includeArtists = false,
    ): SyncLibraryResult {
        $uuid = new \App\Shared\Domain\Model\Uuid($libraryId);
        $library = $this->libraryService->findByUuid($uuid);

        if ($library === null) {
            $this->logger->warning('Library not found for sync', ['library_id' => $libraryId]);

            return new SyncLibraryResult(albumsDispatched: 0, songsDispatched: 0, artistsDispatched: 0);
        }

        $this->logger->info('Starting library metadata sync', [
            'library_id' => $libraryId,
            'force_update' => $forceUpdate,
            'include_songs' => $includeSongs,
            'include_artists' => $includeArtists,
        ]);

        // Dispatch library sync message for async processing
        $this->bus->dispatch(new SyncLibraryMessage(
            $library->getId(),
            $forceUpdate,
            $includeSongs,
            $includeArtists,
        ));

        return new SyncLibraryResult(albumsDispatched: 0, songsDispatched: 0, artistsDispatched: 0);
    }

    public function syncAlbum(string $albumId, bool $forceUpdate = false): void
    {
        $this->bus->dispatch(new SyncAlbumMessage(
            new \App\Shared\Domain\Model\Uuid($albumId),
            $forceUpdate,
        ));
    }

    public function syncArtist(string $artistId, bool $forceUpdate = false): void
    {
        $this->bus->dispatch(new SyncArtistMessage(
            new \App\Shared\Domain\Model\Uuid($artistId),
            $forceUpdate,
        ));
    }

    public function syncSong(string $songId, bool $forceUpdate = false): void
    {
        $this->bus->dispatch(new SyncSongMessage(
            new \App\Shared\Domain\Model\Uuid($songId),
            $forceUpdate,
        ));
    }

    public function syncGenres(bool $forceUpdate = false, bool $includeSongs = true): void
    {
        $this->bus->dispatch(new SyncGenresMessage(
            forceUpdate: $forceUpdate,
            includeSongs: $includeSongs,
        ));

        $this->logger->info('Genre sync dispatched');
    }

    public function syncAll(bool $forceUpdate = false, bool $includeSongs = false): void
    {
        $libraries = $this->libraryService->findAllOrdered();

        $this->logger->info('Dispatching sync for all libraries', [
            'library_count' => count($libraries),
        ]);

        foreach ($libraries as $library) {
            $this->bus->dispatch(new SyncLibraryMessage(
                $library->getId(),
                $forceUpdate,
                $includeSongs,
            ));
        }
    }
}
