<?php

namespace App\Policies;

use App\Models\Genre;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class GenrePolicy
{
    use HandlesAuthorization;

    public function show(User $user = null, Genre $model): bool
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

    public function update(User $user, Genre $model): bool
    {
        return $user->isAdmin();
    }

    public function updateBulk(User $user, Genre $model): bool
    {
        return $user->isAdmin();
    }

    public function deleteBulk(User $user, Genre $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Genre $model): bool
    {
        return $user->isAdmin();
    }
}
