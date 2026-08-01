<?php

declare(strict_types=1);

namespace App\Library\Application\Port;

use App\Shared\Domain\Model\Uuid;

interface LibraryAccessPortInterface
{
    /**
     * Grant a user access to a library. Idempotent.
     */
    public function grant(Uuid $userId, Uuid $libraryId): void;

    /**
     * Revoke a user's access to a library. Idempotent.
     */
    public function revoke(Uuid $userId, Uuid $libraryId): void;

    /**
     * Get all library IDs a user has access to.
     *
     * @return list<string>
     */
    public function getUserLibraryIds(Uuid $userId): array;

    /**
     * Check if a user has access to a specific library.
     */
    public function hasAccess(Uuid $userId, Uuid $libraryId): bool;
}
