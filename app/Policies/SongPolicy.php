<?php

namespace App\Policies;

use App\Models\Song;
use App\Models\User;

class SongPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('song.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Song $song): bool
    {
        if ($user->can('song.viewAny')) {
            return true;
        }

        if ($song->relationLoaded('album')) {
            return $song->userHasAccessToLibrary($user->id, $song->album->library_id);
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Song $song): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Song $song): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Song $song): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Song $song): bool
    {
        return $user->isAdmin();
    }
}
