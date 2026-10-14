<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

/**
 * Finds the user an operator names by email address or UUID, as the `app:user:*` commands
 * do, for commands of other contexts that take a user; and the user a member names by
 * public id, the identifier users share with each other.
 */
interface UserIdentifierResolverInterface
{
    /**
     * @throws NotFoundException when no user has the email address or UUID, or the value is neither
     */
    public function userId(string $identifier): Uuid;

    /**
     * @throws NotFoundException when no user has the public id
     */
    public function userIdByPublicId(PublicId $publicId): Uuid;
}
