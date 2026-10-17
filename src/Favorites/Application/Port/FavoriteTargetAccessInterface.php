<?php

declare(strict_types=1);

namespace App\Favorites\Application\Port;

use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface FavoriteTargetAccessInterface
{
    public function assertVisible(Uuid $actorId, FavoriteType $type, PublicId $publicId): void;
}
