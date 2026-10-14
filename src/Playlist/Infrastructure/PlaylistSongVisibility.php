<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure;

use App\Catalog\Application\Port\SongLookupInterface;
use App\Playlist\Application\Port\PlaylistSongVisibilityInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final class PlaylistSongVisibility implements PlaylistSongVisibilityInterface
{
    public function __construct(
        private readonly SongLookupInterface $songs,
    ) {
    }

    public function isVisible(Uuid $songId, LibraryReadScope $scope): bool
    {
        return $this->songs->visibleSongIds([$songId], $scope) !== [];
    }
}
