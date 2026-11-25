<?php

declare(strict_types=1);

namespace App\Playlist\Application\Port;

use App\Playlist\Domain\Model\Playlist;
use App\Playlist\Domain\ReadModel\PlaylistReadView;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

interface PlaylistPortInterface
{
    /**
     * A page of the playlists the actor owns or collaborates on, by name and then id, each
     * with the actor's visible song count.
     *
     * @return list<PlaylistReadView>
     */
    public function findReadByUser(Uuid $actorId, LibraryReadScope $scope, int $limit, int $offset): array;

    /** The number of playlists the actor owns or collaborates on. */
    public function countReadByUser(Uuid $actorId): int;

    /** One playlist as the viewer reads it: the song count is the viewer's visible count. */
    public function findRead(Uuid $playlistId, LibraryReadScope $scope): ?PlaylistReadView;

    public function save(Playlist $playlist): void;

    public function findByUuid(Uuid $uuid): ?Playlist;

    public function findByPublicId(PublicId $publicId): ?Playlist;

    /**
     * @return Playlist[]
     */
    public function findByUser(Uuid $userId): array;

    public function findWithSongs(Uuid $id): ?Playlist;

    public function delete(Playlist $playlist): void;
}
