<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\Repository\Searchable;

interface ArtistRepositoryInterface extends Searchable
{
    public function findVisibleByPublicId(PublicId $publicId, LibraryReadScope $scope): ?Artist;

    public function findVisibleByUuid(Uuid $uuid, LibraryReadScope $scope): ?Artist;

    public function searchVisible(SearchOptions $options, LibraryReadScope $scope): SearchResult;

    public function countVisible(LibraryReadScope $scope): int;

    public function save(Artist $artist): void;

    public function persist(Artist $artist): void;

    public function flush(): void;

    public function findByUuid(Uuid $uuid): ?Artist;

    public function findByPublicId(PublicId $publicId): ?Artist;

    public function findByMbid(?MusicbrainzId $mbid): ?Artist;

    public function findByName(string $name): ?Artist;

    public function findOrCreateByName(string $name): Artist;

    public function count(): int;

    public function delete(Artist $artist): void;

    /** @return bool false, changing nothing, when the artist or the song does not exist; true when the credit exists afterwards */
    public function addSongToArtist(Uuid $artistId, Uuid $songId, string $role): bool;

    /** @return bool false, changing nothing, when the artist has no credit on the song */
    public function removeSongFromArtist(Uuid $artistId, Uuid $songId): bool;

    /** @return list<string|null> the roles of the artist's credits on the song, empty when it has none */
    public function songCreditRoles(Uuid $artistId, Uuid $songId): array;

    /**
     * Changes the role of the credit holding $currentRole. When the artist already holds $role on
     * the song, the credit holding $currentRole is removed instead.
     *
     * @param string|null $currentRole the role of the credit to change; null names a credit without a role
     * @return bool false, changing nothing, when the artist has no credit with $currentRole on the song
     */
    public function updateSongRole(Uuid $artistId, Uuid $songId, ?string $currentRole, string $role): bool;

    /** @return bool false, changing nothing, when the artist or the album does not exist; true when the credit exists afterwards */
    public function addAlbumToArtist(Uuid $artistId, Uuid $albumId, string $role): bool;

    /** @return bool false, changing nothing, when the artist has no credit on the album */
    public function removeAlbumFromArtist(Uuid $artistId, Uuid $albumId): bool;

    /** @return list<string|null> the roles of the artist's credits on the album, empty when it has none */
    public function albumCreditRoles(Uuid $artistId, Uuid $albumId): array;

    /**
     * Changes the role of the credit holding $currentRole. When the artist already holds $role on
     * the album, the credit holding $currentRole is removed instead.
     *
     * @param string|null $currentRole the role of the credit to change; null names a credit without a role
     * @return bool false, changing nothing, when the artist has no credit with $currentRole on the album
     */
    public function updateAlbumRole(Uuid $artistId, Uuid $albumId, ?string $currentRole, string $role): bool;

    /**
     * @param Uuid[] $uuids
     * @return array<string, Artist> keyed by UUID string
     */
    public function findByUuids(array $uuids): array;
}
