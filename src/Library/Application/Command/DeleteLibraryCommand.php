<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/** Deletes a library: the admin panel's DELETE /api/libraries/{id} and `app:library:delete`. */
final readonly class DeleteLibraryCommand
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
    ) {
    }
}
