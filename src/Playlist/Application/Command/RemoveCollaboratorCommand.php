<?php

declare(strict_types=1);

namespace App\Playlist\Application\Command;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/** Drop the collaborator row of the user with the public id from the playlist. */
final readonly class RemoveCollaboratorCommand
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
