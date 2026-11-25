<?php

declare(strict_types=1);

namespace App\Metadata\Application;

use App\Library\Application\Port\LibraryPortInterface;
use App\Metadata\Application\Message\SyncAlbumMessage;
use App\Metadata\Application\Message\SyncArtistsMessage;
use App\Metadata\Application\Message\SyncGenresMessage;
use App\Metadata\Application\Message\SyncLibraryMessage;
use App\Metadata\Application\Message\SyncPeopleMessage;
use App\Metadata\Application\Message\SyncSongMessage;
use App\Shared\Domain\Model\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

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
    ): SyncLibraryResult {
        $uuid = new Uuid($libraryId);
        $library = $this->libraryService->findByUuid($uuid);

        if ($library === null) {
            $this->logger->warning('Library not found for sync', ['library_id' => $libraryId]);

            return new SyncLibraryResult(albumsDispatched: 0, songsDispatched: 0);
        }

        $this->logger->info('Starting library metadata sync', [
            'library_id' => $libraryId,
            'force_update' => $forceUpdate,
            'include_songs' => $includeSongs,
        ]);

        // Dispatch library sync message for async processing
        $this->bus->dispatch(new SyncLibraryMessage(
            $library->getId(),
            $forceUpdate,
            $includeSongs,
        ));

        return new SyncLibraryResult(albumsDispatched: 0, songsDispatched: 0);
    }

    public function syncAlbum(string $albumId, bool $forceUpdate = false): void
    {
        $this->bus->dispatch(new SyncAlbumMessage(
            new Uuid($albumId),
            $forceUpdate,
        ));
    }

    public function syncSong(string $songId, bool $forceUpdate = false): void
    {
        $this->bus->dispatch(new SyncSongMessage(
            new Uuid($songId),
            $forceUpdate,
        ));
    }

    /**
     * @return int the album and song syncs the genre sync queued, or 1 when the genre sync
     *             itself was queued instead of handled during the dispatch
     */
    public function syncGenres(bool $forceUpdate = false, bool $includeSongs = true): int
    {
        $envelope = $this->bus->dispatch(new SyncGenresMessage(
            forceUpdate: $forceUpdate,
            includeSongs: $includeSongs,
        ));

        $this->logger->info('Genre sync dispatched');

        $queued = $envelope->last(HandledStamp::class)?->getResult();

        return is_int($queued) ? $queued : 1;
    }

    /** @return int the library syncs queued, one per library */
    public function syncAll(bool $forceUpdate = false, bool $includeSongs = false): int
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

        return count($libraries);
    }

    /**
     * Queues the fill of every artist that holds a MusicBrainz ID and misses details, which
     * Catalog walks when the message runs.
     *
     * @return int the artist job queued
     */
    public function syncArtists(): int
    {
        $this->bus->dispatch(new SyncArtistsMessage());
        $this->logger->info('Artist sync dispatched');

        return 1;
    }

    public function syncPeople(): int
    {
        $this->bus->dispatch(new SyncPeopleMessage());
        $this->logger->info('Person sync dispatched');

        return 1;
    }
}
