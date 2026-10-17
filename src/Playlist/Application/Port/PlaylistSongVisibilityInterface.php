<?php

declare(strict_types=1);

namespace App\Playlist\Application\Port;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\ValueObject\LibraryReadScope;

/**
 * Whether the acting user can see a song through their own library membership.
 * A song that does not exist is not visible.
 */
interface PlaylistSongVisibilityInterface
{
    public function findVisibleSongId(PublicId $songId, LibraryReadScope $scope): ?Uuid;

    public function isVisible(Uuid $songId, LibraryReadScope $scope): bool;
}
