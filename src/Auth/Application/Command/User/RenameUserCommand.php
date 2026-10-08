<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/** Changes a user's display name: the admin panel's PATCH /api/admin/users/{id} and `app:user:rename`. */
final readonly class RenameUserCommand
{
    public function __construct(
        /** The user's email address or UUID. */
        public string $identifier,
        public string $name,
    ) {
    }
}
