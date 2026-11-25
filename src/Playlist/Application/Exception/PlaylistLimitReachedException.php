<?php

declare(strict_types=1);

namespace App\Playlist\Application\Exception;

use App\Playlist\Application\PlaylistLimits;
use App\Shared\Application\Exception\ConflictException;

/** A count cap of PlaylistLimits is reached; the change would add one more. */
final class PlaylistLimitReachedException extends ConflictException
{
    public static function playlists(): self
    {
        return new self(
            sprintf('An owner may keep at most %d playlists.', PlaylistLimits::PLAYLISTS_PER_OWNER),
            ['reason' => 'playlist_limit', 'limit' => PlaylistLimits::PLAYLISTS_PER_OWNER],
        );
    }

    public static function songs(): self
    {
        return new self(
            sprintf('A playlist may hold at most %d songs.', PlaylistLimits::SONGS_PER_PLAYLIST),
            ['reason' => 'song_limit', 'limit' => PlaylistLimits::SONGS_PER_PLAYLIST],
        );
    }

    public static function collaborators(): self
    {
        return new self(
            sprintf('A playlist may have at most %d collaborators.', PlaylistLimits::COLLABORATORS_PER_PLAYLIST),
            ['reason' => 'collaborator_limit', 'limit' => PlaylistLimits::COLLABORATORS_PER_PLAYLIST],
        );
    }
}
