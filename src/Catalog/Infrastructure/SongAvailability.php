<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\SongAvailabilityPortInterface;
use App\Library\Application\Port\LibraryFileAvailabilityInterface;

final readonly class SongAvailability implements SongAvailabilityPortInterface
{
    public function __construct(
        private AlbumPortInterface $albums,
        private LibraryFileAvailabilityInterface $files,
    ) {
    }

    public function forSongs(array $songs): array
    {
        if ($songs === []) {
            return [];
        }

        $albumIds = [];
        foreach ($songs as $song) {
            $albumIds[$song->getAlbumId()->toString()] = $song->getAlbumId();
        }
        $albums = $this->albums->findByUuids(array_values($albumIds));
        $libraries = [];
        $paths = [];
        foreach ($songs as $song) {
            $album = $albums[$song->getAlbumId()->toString()] ?? null;
            if ($album === null) {
                continue;
            }
            $libraryId = $album->getLibraryId();
            $libraryKey = $libraryId->toString();
            $libraries[$libraryKey] = $libraryId;
            $paths[$libraryKey][$song->getPath()] = $song->getPath();
        }

        $observations = [];
        foreach ($libraries as $key => $libraryId) {
            $observations[$key] = $this->files->findAvailabilityByPaths($libraryId, array_values($paths[$key]));
        }

        $availability = [];
        foreach ($songs as $song) {
            $album = $albums[$song->getAlbumId()->toString()] ?? null;
            $availability[$song->getId()->toString()] = $album !== null
                && ($observations[$album->getLibraryId()->toString()][$song->getPath()] ?? false);
        }

        return $availability;
    }
}
