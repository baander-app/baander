<?php

declare(strict_types=1);

namespace App\Playlist\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class UpdatePlaylistCommand
{
    public function __construct(
        private Uuid $playlistId,
        private string $name,
        private ?string $description,
        private bool $isPublic,
    ) {
    }

    public function getPlaylistId(): Uuid
    {
        return $this->playlistId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }
}
