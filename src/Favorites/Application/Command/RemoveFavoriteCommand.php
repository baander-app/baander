<?php

declare(strict_types=1);

namespace App\Favorites\Application\Command;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final readonly class RemoveFavoriteCommand
{
    public function __construct(
        private Uuid $userId,
        private PublicId $publicId,
    ) {
    }

    public function getUserId(): Uuid { return $this->userId; }
    public function getPublicId(): PublicId { return $this->publicId; }
}
