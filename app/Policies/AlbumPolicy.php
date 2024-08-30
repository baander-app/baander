<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AlbumPolicy
{
    use HandlesAuthorization;

    public function show(User $user = null, Album $model): bool
    {
        return (bool)$user;
    }

    public function store(User $user): bool
    {
        return $user->isAdmin();
    }

    public function storeBulk(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Album $model): bool
    {
        return $user->isAdmin();
    }

    public function updateBulk(User $user, Album $model): bool
    {
        return $user->isAdmin();
    }

    public function deleteBulk(User $user, Album $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Album $model): bool
    {
        return $user->isAdmin();
    }
}
