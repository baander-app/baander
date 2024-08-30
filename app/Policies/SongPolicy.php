<?php

namespace App\Policies;

use App\Models\Song;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SongPolicy
{
    use HandlesAuthorization;

    public function show(User $user = null, Song $model): bool
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

    public function update(User $user, Song $model): bool
    {
        return $user->isAdmin();
    }

    public function updateBulk(User $user, Song $model): bool
    {
        return $user->isAdmin();
    }

    public function deleteBulk(User $user, Song $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Song $model): bool
    {
        return $user->isAdmin();
    }
}
