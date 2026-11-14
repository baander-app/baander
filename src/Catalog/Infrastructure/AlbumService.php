<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Service\CoverImageDiscarder;
use App\Catalog\Application\Port\AlbumPortInterface;
use App\Catalog\Application\Port\ArtistIdentity;
use App\Catalog\Application\Port\ArtistIdentityResolverInterface;
use App\Catalog\Domain\Model\Album;
use App\Catalog\Domain\Repository\AlbumRepositoryInterface;
use App\Catalog\Domain\ValueObject\ArtistRole;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Application\Port\TransactionPortInterface;
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
        private readonly TransactionPortInterface $transaction,
        private readonly ArtistIdentityResolverInterface $artistIdentities,
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

    public function findFreshByUuid(Uuid $uuid): ?Album
    {
        return $this->albumRepository->findFreshByUuid($uuid);
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

    public function creditArtistIfUncredited(Uuid $albumId, ?ArtistIdentity $albumArtist = null, ?string $creditedName = null): void
    {
        $this->transaction->run(function () use ($albumId, $albumArtist, $creditedName): void {
            $songCredits = $this->albumRepository->lockForArtistCredit($albumId);
            if ($songCredits === null || $songCredits === []) {
                return;
            }

            // Resolved only once the album is known to need a credit, so a credited album creates no artist.
            $credit = $albumArtist === null
                ? self::sharedArtist($songCredits)
                : [
                    'artistId' => $this->artistIdentities->resolve([$albumArtist])[$albumArtist->key()],
                    'creditedName' => $creditedName ?? $albumArtist->name,
                ];
            if ($credit === null) {
                return;
            }

            $this->albumRepository->addArtistCredit($albumId, $credit['artistId'], ArtistRole::Primary->value, $credit['creditedName']);
            $this->albumRepository->flush();
        });
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

    /**
     * The artist every song is credited to, under the credited name all those credits share, else
     * under the artist's own name. Null when a song has no primary credit or the songs credit more
     * than one artist.
     *
     * @param non-empty-array<string, list<array{artistId: Uuid, artistName: string, creditedName: string}>> $songCredits each song's primary credits
     *
     * @return array{artistId: Uuid, creditedName: string}|null
     */
    private static function sharedArtist(array $songCredits): ?array
    {
        $artist = null;
        $creditedNames = [];
        foreach ($songCredits as $credits) {
            if ($credits === []) {
                return null;
            }
            foreach ($credits as $credit) {
                if ($artist !== null && !$artist['artistId']->equals($credit['artistId'])) {
                    return null;
                }
                $artist ??= $credit;
                $creditedNames[$credit['creditedName']] = true;
            }
        }

        return [
            'artistId' => $artist['artistId'],
            'creditedName' => count($creditedNames) === 1 ? (string) array_key_first($creditedNames) : $artist['artistName'],
        ];
    }
}
