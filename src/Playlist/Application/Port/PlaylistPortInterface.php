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
    /** @return list<PlaylistReadView> */
    public function findReadByUser(Uuid $ownerId, LibraryReadScope $scope): array;

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
