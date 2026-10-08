<?php

declare(strict_types=1);

namespace App\Auth\Application\Command\User;

/** Replaces a user's roles: the admin panel's POST /api/admin/users/{id}/roles and `app:user:roles`. */
final readonly class SetUserRolesCommand
{
    /**
     * @param list<string> $roles the complete new set of roles, such as ROLE_USER and ROLE_ADMIN
     */
    public function __construct(
        /** The user's email address or UUID. */
        public string $identifier,
        public array $roles,
    ) {
    }
}
