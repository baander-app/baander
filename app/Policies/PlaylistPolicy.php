<?php

namespace App\Policies;

use App\Models\{Playlist, User};

class PlaylistPolicy
{
    public function view(User $user, Playlist $playlist)
    {
        return $playlist->is_public || $playlist->user_id === $user->id;
    }

    public function addSong(User $user, Playlist $playlist)
    {
        return $playlist->canAddSongs($user);
    }

    public function update(User $user, Playlist $playlist)
    {
        return $playlist->canEdit($user);
    }

    public function delete(User $user, Playlist $playlist)
    {
        return $playlist->user_id === $user->id;
    }
}
