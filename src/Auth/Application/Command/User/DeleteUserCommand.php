<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/** Deletes a user account: the admin panel's DELETE /api/admin/users/{id} and `app:user:delete`. */
final readonly class DeleteUserCommand
{
    public function __construct(
        /** The user's email address or UUID. */
        public string $identifier,
    ) {
    }
}
