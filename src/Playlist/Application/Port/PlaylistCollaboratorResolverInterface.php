<?php

declare(strict_types=1);

namespace App\Playlist\Application\Port;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/**
 * Finds the user a playlist owner names as a collaborator. Users are named by public id,
 * the identifier they share with each other; nothing else identifies a user on this path.
 */
interface PlaylistCollaboratorResolverInterface
{
    /**
     * @throws NotFoundException when no user has the public id
     */
    public function userId(PublicId $publicId): Uuid;
}
