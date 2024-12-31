<?php

namespace App\Models\Concerns;

use App\Models\UserLibrary;

trait HasLibraryAccess
{
    /**
     * Determine if a user has access to the library.
     *
     * @param int $userId
     * @param int $libraryId
     * @return bool
     */
    public function userHasAccessToLibrary(int $userId, int $libraryId): bool
    {
        return UserLibrary::where('user_id', $userId)
            ->where('library_id', $libraryId)
            ->exists();
    }
}