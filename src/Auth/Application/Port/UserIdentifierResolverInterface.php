<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/**
 * Finds the user an operator names by email address or public ID, as the `app:user:*` commands
 * do, for commands of other contexts that take a user; and the user a member names by
 * public id, the identifier users share with each other.
 */
interface UserIdentifierResolverInterface
{
    /**
     * @throws NotFoundException when no user has the email address or public ID, or the value is neither
     */
    public function userId(string $identifier): Uuid;

    /**
     * @throws NotFoundException when no user has the public id
     */
    public function userIdByPublicId(PublicId $publicId): Uuid;

    /** @throws NotFoundException when the internal user identity cannot be resolved */
    public function publicIdByUserId(Uuid $userId): PublicId;
}
