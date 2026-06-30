<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Catalog\Domain\Model\Artist;
use App\Catalog\Domain\ValueObject\MusicbrainzId;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;
use App\Shared\Domain\Model\Uuid;

interface ArtistPortInterface
{
    public function findByPublicId(PublicId $publicId): ?Artist;

    public function findByUuid(Uuid $uuid): ?Artist;

    public function findByMbid(?MusicbrainzId $mbid): ?Artist;

    public function findByName(string $name): ?Artist;

    public function findOrCreateByName(string $name): Artist;

    public function search(SearchOptions $options): SearchResult;

    public function count(): int;

    public function save(Artist $artist): void;

    public function persist(Artist $artist): void;

    public function flush(): void;

    public function delete(Artist $artist): void;

    public function addSongToArtist(Uuid $artistId, Uuid $songId, string $role): void;

    public function removeSongFromArtist(Uuid $artistId, Uuid $songId): void;

    public function updateSongRole(Uuid $artistId, Uuid $songId, string $role): void;

    public function addAlbumToArtist(Uuid $artistId, Uuid $albumId, string $role): void;

    public function removeAlbumFromArtist(Uuid $artistId, Uuid $albumId): void;

    public function updateAlbumRole(Uuid $artistId, Uuid $albumId, string $role): void;
}
