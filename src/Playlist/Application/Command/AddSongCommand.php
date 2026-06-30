<?php

declare(strict_types=1);

namespace App\Playlist\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class AddSongCommand
{
    public function __construct(
        private Uuid $playlistId,
        private Uuid $songId,
        private int $position,
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
}
