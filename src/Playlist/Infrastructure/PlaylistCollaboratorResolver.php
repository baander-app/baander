<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure;

use App\Auth\Application\Port\UserIdentifierResolverInterface;
use App\Playlist\Application\Port\PlaylistCollaboratorResolverInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/**
 * Resolves a collaborator's public id through the Auth contract's public-id lookup and
 * nothing else: the email-or-UUID lookup stays out of reach of a member route.
 */
final class PlaylistCollaboratorResolver implements PlaylistCollaboratorResolverInterface
{
    public function __construct(
        private readonly UserIdentifierResolverInterface $users,
    ) {
    }

    public function userId(PublicId $publicId): Uuid
    {
        return $this->users->userIdByPublicId($publicId);
    }
}
