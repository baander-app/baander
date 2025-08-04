<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\User;

class AlbumPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('albums.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Album $album): bool
    {
        if ($user->can('album.viewAny')) {
            return true;
        }

        return $album->userHasAccessToLibrary($user->id, $album->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('album.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Album $album): bool
    {
        return $user->can('album.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Album $album): bool
    {
        return $user->can('album.delete');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Album $album): bool
    {
        return $user->can('album.delete');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Album $album): bool
    {
        return $user->can('album.forceDelete');
    }
}
