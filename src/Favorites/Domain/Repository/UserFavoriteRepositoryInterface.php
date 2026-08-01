<?php

declare(strict_types=1);

namespace App\Favorites\Domain\Repository;

use App\Favorites\Domain\Model\UserFavorite;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

interface UserFavoriteRepositoryInterface
{
    public function save(UserFavorite $favorite): void;

    public function findByUuid(Uuid $uuid): ?UserFavorite;

    public function findByPublicId(PublicId $publicId): ?UserFavorite;

    public function findByUserAndEntity(Uuid $userId, FavoriteType $entityType, string $entityPublicId): ?UserFavorite;

    /**
     * @return UserFavorite[]
     */
    public function findByUser(Uuid $userId, ?FavoriteType $entityType = null, int $limit = 50, int $offset = 0): array;

    public function countByUser(Uuid $userId, ?FavoriteType $entityType = null): int;

    public function delete(UserFavorite $favorite): void;
}
