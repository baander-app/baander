<?php

namespace App\Policies;

use App\Models\Library;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LibraryPolicy
{
    use HandlesAuthorization;

    public function show(User $user = null, Library $model): bool
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

    public function update(User $user, Library $model): bool
    {
        return $user->isAdmin();
    }

    public function updateBulk(User $user, Library $model): bool
    {
        return $user->isAdmin();
    }

    public function deleteBulk(User $user, Library $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Library $model): bool
    {
        return $user->isAdmin();
    }
}
