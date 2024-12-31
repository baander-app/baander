<?php

namespace App\Policies;

use App\Models\Image;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ImagePolicy
{
    use HandlesAuthorization;

    public function show(User $user = null, Image $model): bool
    {
        return true;
    }

    public function store(User $user): bool
    {
        return false;
    }

    public function storeBulk(User $user): bool
    {
        return false;
    }

    public function update(User $user, Image $model): bool
    {
        return false;
    }

    public function updateBulk(User $user, Image $model): bool
    {
        return false;
    }

    public function deleteBulk(User $user, Image $model): bool
    {
        return false;
    }

    public function delete(User $user, Image $model): bool
    {
        return false;
    }
}
