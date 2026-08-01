<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\Repository\ArtistRepositoryInterface;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;

final class ArtistService implements ArtistPortInterface
{
    public function __construct(
        private readonly ArtistRepositoryInterface $artistRepository,
    ) {
    }

    public function findByPublicId(PublicId $publicId): ?Artist
    {
        return $this->artistRepository->findByPublicId($publicId);
    }

    public function findByUuid(Uuid $uuid): ?Artist
    {
        return $this->artistRepository->findByUuid($uuid);
    }

    public function findByMbid(?MusicbrainzId $mbid): ?Artist
    {
        return $this->artistRepository->findByMbid($mbid);
    }

    public function findByName(string $name): ?Artist
    {
        return $this->artistRepository->findByName($name);
    }

    public function findOrCreateByName(string $name): Artist
    {
        return $this->artistRepository->findOrCreateByName($name);
    }

    public function search(SearchOptions $options): SearchResult
    {
        return $this->artistRepository->search($options);
    }

    public function count(): int
    {
        return $this->artistRepository->count();
    }

    public function save(Artist $artist): void
    {
        $this->artistRepository->save($artist);
    }

    public function persist(Artist $artist): void
    {
        $this->artistRepository->persist($artist);
    }

    public function flush(): void
    {
        $this->artistRepository->flush();
    }

    public function delete(Artist $artist): void
    {
        $this->artistRepository->delete($artist);
    }

    public function addSongToArtist(Uuid $artistId, Uuid $songId, string $role): void
    {
        $this->artistRepository->addSongToArtist($artistId, $songId, $role);
    }

    public function removeSongFromArtist(Uuid $artistId, Uuid $songId): void
    {
        $this->artistRepository->removeSongFromArtist($artistId, $songId);
    }

    public function updateSongRole(Uuid $artistId, Uuid $songId, string $role): void
    {
        $this->artistRepository->updateSongRole($artistId, $songId, $role);
    }

    public function addAlbumToArtist(Uuid $artistId, Uuid $albumId, string $role): void
    {
        $this->artistRepository->addAlbumToArtist($artistId, $albumId, $role);
    }

    public function removeAlbumFromArtist(Uuid $artistId, Uuid $albumId): void
    {
        $this->artistRepository->removeAlbumFromArtist($artistId, $albumId);
    }

    public function updateAlbumRole(Uuid $artistId, Uuid $albumId, string $role): void
    {
        $this->artistRepository->updateAlbumRole($artistId, $albumId, $role);
    }
}
