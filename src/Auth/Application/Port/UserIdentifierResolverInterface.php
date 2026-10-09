<?php

declare(strict_types=1);

namespace App\Auth\Application\Port;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\Uuid;

/**
 * Finds the user an operator names by email address or UUID, as the `app:user:*` commands
 * do, for commands of other contexts that take a user.
 */
interface UserIdentifierResolverInterface
{
    /**
     * @throws NotFoundException when no user has the email address or UUID, or the value is neither
     */
    public function userId(string $identifier): Uuid;
}
