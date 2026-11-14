<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Domain\Model\Song;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Catalog\Infrastructure\Doctrine\ImportIdentity\ImportIdentityLock;
use App\Shared\Domain\Model\CursorPage;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use Doctrine\DBAL\Connection;

final class SongService implements SongPortInterface
{
    public function __construct(
        private readonly SongRepositoryInterface $songRepository,
        private readonly Connection $connection,
    ) {
    }

    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Song
    {
        return $this->songRepository->findVisibleByPublicId($publicId, $scope);
    }

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Song
    {
        return $this->songRepository->findVisibleByUuid($uuid, $scope);
    }

    public function findVisibleByUuids(array $uuids, LibraryReadScope $scope): array
    {
        return $this->songRepository->findVisibleByUuids($uuids, $scope);
    }

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult
    {
        return $this->songRepository->searchVisible($options, $scope);
    }

    public function countVisible(LibraryReadScope $scope): int
    {
        return $this->songRepository->countVisible($scope);
    }

    public function searchVisibleWithCursor(SearchOptions $options, LibraryReadScope $scope): CursorPage
    {
        return $this->songRepository->searchVisibleWithCursor($options, $scope);
    }

    /**
     * @param Uuid[] $songIds
     * @return array<string, string>
     */
    public function getVisibleArtistNamesForSongs(array $songIds, LibraryReadScope $scope): array
    {
        return $this->songRepository->getVisibleArtistNamesForSongs($songIds, $scope);
    }

    public function getVisibleAlbumTitlesAndLanguagesByIds(array $albumIds, LibraryReadScope $scope): array
    {
        return $this->songRepository->getVisibleAlbumTitlesAndLanguagesByIds($albumIds, $scope);
    }

    public function findByPublicId(PublicId $publicId): ?Song
    {
        return $this->songRepository->findByPublicId($publicId);
    }

    public function findByUuid(Uuid $uuid): ?Song
    {
        return $this->songRepository->findByUuid($uuid);
    }

    public function findFreshByUuid(Uuid $uuid): ?Song
    {
        return $this->songRepository->findFreshByUuid($uuid);
    }

    public function lockForRetag(array $artists, array $genres, array $songIds): void
    {
        $this->lockImportIdentities($artists, $genres);
        $this->songRepository->lockInIdOrder($songIds);
    }

    public function lockImportIdentities(array $artists, array $genres): void
    {
        $artistKeys = array_map(ImportIdentityLock::artistKeys(...), $artists)
            |> (static fn (array $keys): array => array_merge(...$keys));
        $genreKeys = array_map(static fn (string $genre): string => ImportIdentityLock::genreSlug($genre) |> ImportIdentityLock::genreKey(...), $genres);
        // Artist keys sort before genre keys, so this pass takes them in the order the artist
        // resolver and the genre lookup take theirs.
        ImportIdentityLock::acquire($this->connection, [...$artistKeys, ...$genreKeys]);
    }

    public function lockInIdOrder(array $songIds): void
    {
        // Outside a transaction the row locks would end with their own statement.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Lock song rows inside the transaction that writes them.');
        }
        $this->songRepository->lockInIdOrder($songIds);
    }

    public function findByPathsInLibrary(Uuid $libraryId, array $paths): array
    {
        return $this->songRepository->findByPathsInLibrary($libraryId, $paths);
    }

    public function findByHash(string $hash): ?Song
    {
        return $this->songRepository->findByHash($hash);
    }

    /**
     * @return Song[]
     */
    public function findByAlbum(Uuid $albumId, int $limit = 100): array
    {
        return $this->songRepository->findByAlbum($albumId, $limit);
    }

    /**
     * @return Song[]
     */
    public function findByAlbumSortedByTrack(Uuid $albumId): array
    {
        return $this->songRepository->findByAlbumSortedByTrack($albumId);
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->songRepository->search($options);
    }

    public function searchWithCursor(SearchOptions $options): CursorPage
    {
        return $this->songRepository->searchWithCursor($options);
    }

    public function count(): int
    {
        return $this->songRepository->count();
    }

    public function countByAlbum(Uuid $albumId): int
    {
        return $this->songRepository->countByAlbum($albumId);
    }

    public function save(Song $song): void
    {
        $this->songRepository->save($song);
    }

    public function persist(Song $song): void
    {
        $this->songRepository->persist($song);
    }

    public function flush(): void
    {
        $this->songRepository->flush();
    }

    public function delete(Song $song): void
    {
        $this->songRepository->delete($song);
    }

    public function addArtistCredit(Uuid $songId, Uuid $artistId, string $role, string $creditedName): void
    {
        $this->songRepository->addArtistCredit($songId, $artistId, $role, $creditedName);
    }

    public function removeArtistCredits(Uuid $songId): void
    {
        $this->songRepository->removeArtistCredits($songId);
    }

    public function replacePerformerCredits(Uuid $songId, array $credits): bool
    {
        return $this->songRepository->replacePerformerCredits($songId, $credits);
    }

    public function findCreditedSongIds(array $songIds): array
    {
        return $this->songRepository->findCreditedSongIds($songIds);
    }

    public function getArtistNameForSong(Uuid $songId): ?string
    {
        return $this->songRepository->getArtistNameForSong($songId);
    }

    public function getArtistNamesForSongs(array $songIds): array
    {
        return $this->songRepository->getArtistNamesForSongs($songIds);
    }

    public function getAlbumTitlesAndLanguagesByIds(array $albumIds): array
    {
        return $this->songRepository->getAlbumTitlesAndLanguagesByIds($albumIds);
    }

    public function findByUuids(array $uuids): array
    {
        return $this->songRepository->findByUuids($uuids);
    }
}
