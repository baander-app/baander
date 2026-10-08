<?php

declare(strict_types=1);

namespace App\Library\Application\Command;

/** Renames or reorders a library: the admin panel's PATCH /api/libraries/{id} and `app:library:update`. */
final readonly class UpdateLibraryCommand
{
    public function __construct(
        /** The library's UUID or slug. */
        public string $library,
        /** The new name; null keeps the current one. */
        public ?string $name = null,
        /** The new sort order; null keeps the current one. */
        public ?int $sortOrder = null,
    ) {
    }
}
