<?php

declare(strict_types=1);

namespace App\Playlist\Application\Command;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/** Give the user with the public id a collaborator row on the playlist. */
final readonly class AddCollaboratorCommand
{
    public function __construct(
        private Uuid $playlistId,
        private PublicId $userPublicId,
    ) {
    }

    public function getPlaylistId(): Uuid
    {
        return $this->playlistId;
    }

    public function getUserPublicId(): PublicId
    {
        return $this->userPublicId;
    }
}
