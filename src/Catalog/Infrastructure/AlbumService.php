<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\CommandHandler\Cover\CoverImageDiscarder;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final class AlbumService implements AlbumPortInterface
{
    public function __construct(
        private readonly AlbumRepositoryInterface $albumRepository,
        private readonly CoverImageDiscarder $covers,
    ) {
    }

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Album
    {
        return $this->albumRepository->findVisibleByPublicId($publicId, $scope);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Album
    {
        return $this->albumRepository->findVisibleByUuid($uuid, $scope);
    }

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        return $this->albumRepository->searchVisible($options, $scope);
    }

    public function countVisible(LibraryReadScope $scope): int
    {
        return $this->albumRepository->countVisible($scope);
    }

    /** @return array{0: Album, 1: array<int, \App\Catalog\Domain\Model\Song>}|null */
    public function findVisibleWithSongs(Uuid $uuid, LibraryReadScope $scope): ?array
    {
        return $this->albumRepository->findVisibleWithSongs($uuid, $scope);
    }

    /** @return array<int, array{name: string, role: string|null}> */
    public function getVisibleArtistNamesForAlbum(Uuid $albumId, LibraryReadScope $scope): array
    {
        return $this->albumRepository->getVisibleArtistNamesForAlbum($albumId, $scope);
    }

    public function findByPublicId(PublicId $publicId): ?Album
    {
        return $this->albumRepository->findByPublicId($publicId);
    }

    public function findByUuid(Uuid $uuid): ?Album
    {
        return $this->albumRepository->findByUuid($uuid);
    }

    public function findByMbid(?MusicbrainzId $mbid): ?Album
    {
        return $this->albumRepository->findByMbid($mbid);
    }

    public function findByMbidAndLibrary(?MusicbrainzId $mbid, Uuid $libraryId): ?Album
    {
        return $this->albumRepository->findByMbidAndLibrary($mbid, $libraryId);
    }

    public function findByTitleAndLibrary(string $title, Uuid $libraryId): ?Album
    {
        return $this->albumRepository->findByTitleAndLibrary($title, $libraryId);
    }

    /**
     * @return Album[]
     */
    public function findByLibrary(Uuid $libraryId): array
    {
        return $this->albumRepository->findByLibrary($libraryId);
    }

    /**
     * @return array{0: Album, 1: mixed[]}|null
     */
    public function findWithSongs(Uuid $uuid): ?array
    {
        return $this->albumRepository->findWithSongs($uuid);
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->albumRepository->search($options);
    }

    public function count(): int
    {
        return $this->albumRepository->count();
    }

    public function countCoverlessAlbums(): int
    {
        return $this->albumRepository->countCoverlessAlbums();
    }

    /**
     * @return Uuid[]
     */
    public function findCoverlessAlbumIds(int $limit = 500, int $offset = 0): array
    {
        return $this->albumRepository->findCoverlessAlbumIds($limit, $offset);
    }

    public function save(Album $album): void
    {
        $this->albumRepository->save($album);
    }

    public function persist(Album $album): void
    {
        $this->albumRepository->persist($album);
    }

    public function flush(): void
    {
        $this->albumRepository->flush();
    }

    public function delete(Album $album, bool $deleteCover = true): void
    {
        $coverImageId = $deleteCover ? $album->getCoverImageId() : null;

        $this->albumRepository->delete($album);

        if ($coverImageId !== null) {
            $this->covers->discard($coverImageId);
        }
    }

    public function linkArtistToAlbum(Uuid $albumId, string $artistName, string $role): void
    {
        $this->albumRepository->linkArtistToAlbum($albumId, $artistName, $role);
    }

    /**
     * @return array<int, array{name: string, role: string|null}>
     */
    public function getArtistNamesForAlbum(Uuid $albumId): array
    {
        return $this->albumRepository->getArtistNamesForAlbum($albumId);
    }

    public function getArtistNamesForAlbums(array $albumIds): array
    {
        return $this->albumRepository->getArtistNamesForAlbums($albumIds);
    }

    public function findByUuids(array $uuids): array
    {
        return $this->albumRepository->findByUuids($uuids);
    }
}
