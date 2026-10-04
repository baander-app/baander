<?php

declare(strict_types=1);

namespace App\Playlist\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class CreatePlaylistCommand
{
    /** @param array<array-key, mixed> $smartRules */
    public function __construct(
        private string $name,
        private Uuid $userId,
        private ?string $description = null,
        private bool $isPublic = false,
        private bool $isCollaborative = false,
        private bool $isSmart = false,
        private array $smartRules = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function isCollaborative(): bool
    {
        return $this->isCollaborative;
    }

    public function isSmart(): bool
    {
        return $this->isSmart;
    }

    /** @return array<array-key, mixed> */
    public function getSmartRules(): array
    {
        return $this->smartRules;
    }
}
