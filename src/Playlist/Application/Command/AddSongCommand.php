<?php

declare(strict_types=1);

namespace App\Playlist\Application\Command;

use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;

final readonly class AddSongCommand
{
    /**
     * @param LibraryReadScope $scope the acting user's library read scope; the song must be visible under it
     */
    public function __construct(
        private Uuid $playlistId,
        private Uuid $songId,
        private int $position,
        private LibraryReadScope $scope,
    ) {
    }

    public function getPlaylistId(): Uuid
    {
        return $this->playlistId;
    }

    public function getSongId(): Uuid
    {
        return $this->songId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getScope(): LibraryReadScope
    {
        return $this->scope;
    }
}
