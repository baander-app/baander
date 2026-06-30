<?php

declare(strict_types=1);

namespace App\Activity\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class RecordPlayCommand
{
    public function __construct(
        private Uuid $userId,
        private ?Uuid $songId = null,
        private ?Uuid $albumId = null,
        private ?Uuid $artistId = null,
        private ?Uuid $movieId = null,
        private ?string $platform = null,
        private ?string $player = null,
    ) {
    }

    public function getUserId(): Uuid { return $this->userId; }
    public function getSongId(): ?Uuid { return $this->songId; }
    public function getAlbumId(): ?Uuid { return $this->albumId; }
    public function getArtistId(): ?Uuid { return $this->artistId; }
    public function getMovieId(): ?Uuid { return $this->movieId; }
    public function getPlatform(): ?string { return $this->platform; }
    public function getPlayer(): ?string { return $this->player; }
}
