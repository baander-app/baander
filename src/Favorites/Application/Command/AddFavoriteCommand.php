<?php

declare(strict_types=1);

namespace App\Favorites\Application\Command;

use App\Shared\Domain\Model\Uuid;

final readonly class AddFavoriteCommand
{
    public function __construct(
        private Uuid $userId,
        private string $entityType,
        private string $entityPublicId,
    ) {
    }

    public function getUserId(): Uuid { return $this->userId; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityPublicId(): string { return $this->entityPublicId; }
}
