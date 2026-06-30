<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Playlist\Domain\Model\Playlist;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class PlaylistResultStamp implements StampInterface
{
    public function __construct(
        private Playlist $playlist,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof Playlist ? new self($result) : null;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }
}
