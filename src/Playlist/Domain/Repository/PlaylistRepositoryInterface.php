<?php

declare(strict_types=1);

namespace App\Playlist\Domain\Repository;

use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface PlaylistRepositoryInterface
{
    public function save(Playlist $playlist): void;

    public function findByUuid(Uuid $uuid): ?Playlist;

    public function findByPublicId(PublicId $publicId): ?Playlist;

    /**
     * @return Playlist[]
     */
    public function findByUser(Uuid $userId): array;

    /**
     * The playlists the actor owns or collaborates on, by name, each with the number of
     * songs the actor can see under the scope.
     *
     * @return list<PlaylistReadView>
     */
    public function findReadByUser(Uuid $actorId, LibraryReadScope $scope): array;

    /** One playlist as the viewer reads it: the song count is the viewer's visible count. */
    public function findRead(Uuid $playlistId, LibraryReadScope $scope): ?PlaylistReadView;

    public function findWithSongs(Uuid $id): ?Playlist;

    /**
     * Find playlists containing a specific song.
     *
     * @return array<array{uuid: string, name: string}>
     */
    public function findPlaylistNamesContainingSong(Uuid $songId): array;

    public function delete(Playlist $playlist): void;
}
