<?php

declare(strict_types=1);

namespace App\Playlist\Domain\Model;

use App\Shared\Domain\Model\Uuid;

final class PlaylistSong
{
    public function __construct(
        private readonly Uuid $songId,
        private readonly int $position,
    ) {
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
