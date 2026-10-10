<?php

declare(strict_types=1);

namespace App\Library\Application\Query;

/**
 * Every library, in display order, with whether a user may see it:
 * GET /api/admin/users/{userId}/libraries and `app:library:member:list`.
 */
final readonly class ListLibraryAccessQuery
{
    public function __construct(
        /** The user's UUID or email address. */
        public string $user,
    ) {
    }
}
