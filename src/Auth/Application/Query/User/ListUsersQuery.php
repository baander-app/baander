<?php

declare(strict_types=1);

namespace App\Auth\Application\Query\User;

/**
 * One page of users, newest first: the admin panel's GET /api/admin/users and `app:user:list`.
 */
final readonly class ListUsersQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 100;

    public function __construct(
        /** Only users holding this role, such as ROLE_ADMIN; null for every user. */
        public ?string $role = null,
        /** Only disabled (true) or only enabled (false) users; null for both. */
        public ?bool $disabled = null,
        /** 1 to MAX_LIMIT. */
        public int $limit = self::DEFAULT_LIMIT,
        public int $offset = 0,
    ) {
    }
}
