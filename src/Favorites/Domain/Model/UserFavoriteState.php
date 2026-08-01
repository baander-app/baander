<?php

declare(strict_types=1);

namespace App\Favorites\Domain\Model;

use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use DateTimeImmutable;

/**
 * Internal state for UserFavorite aggregate root.
 *
 * This class is mutable and should only be used by the aggregate root
 * and its repository implementation.
 */
final class UserFavoriteState
{
    public function __construct(
        public readonly Uuid $id,
        public readonly PublicId $publicId,
        public readonly Uuid $userId,
        public readonly FavoriteType $entityType,
        public readonly string $entityPublicId,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {
    }
}
