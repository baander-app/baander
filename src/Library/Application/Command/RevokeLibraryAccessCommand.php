<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/** Stops a user seeing a library: DELETE /api/admin/users/{userId}/libraries/{libraryId} and `app:library:member:revoke`. */
final readonly class RevokeLibraryAccessCommand
{
    public function __construct(
        /** The user's public ID or email address. */
        public string $user,
        /** The library's public ID or slug. */
        public string $library,
    ) {
    }
}
