<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function show(User $user = null, User $model): bool
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

    public function update(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        return $user->isAdmin();
    }

    public function updateBulk(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function deleteBulk(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return true;
        }

        return $user->isAdmin();
    }
}
