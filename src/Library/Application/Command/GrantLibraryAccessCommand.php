<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/** Lets a user see a library: PUT /api/admin/users/{userId}/libraries/{libraryId} and `app:library:member:grant`. */
final readonly class GrantLibraryAccessCommand
{
    public function __construct(
        /** The user's UUID or email address. */
        public string $user,
        /** The library's UUID or slug. */
        public string $library,
    ) {
    }
}
