<?php

namespace App\Policies;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ArtistPolicy
{
    use HandlesAuthorization;

    public function show(User $user = null, Artist $model): bool
    {
        return true;
    }

    public function store(User $user): bool
    {
        return $user->isAdmin();
    }

    public function storeBulk(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Artist $model): bool
    {
        return $user->isAdmin();
    }

    public function updateBulk(User $user, Artist $model): bool
    {
        return $user->isAdmin();
    }

    public function deleteBulk(User $user, Artist $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Artist $model): bool
    {
        return $user->isAdmin();
    }
}
