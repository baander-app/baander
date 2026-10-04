<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Playlist\Domain\Model\Playlist;

final readonly class PlaylistResultStamp implements ResultStampInterface
{
    public function __construct(
        private Playlist $playlist,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof Playlist ? new self($result) : null;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }
}
