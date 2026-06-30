<?php

declare(strict_types=1);

namespace App\Favorites\Infrastructure;

use App\Favorites\Application\Port\FavoritesPortInterface;
use App\Favorites\Domain\Model\UserFavorite;
use App\Favorites\Domain\Repository\UserFavoriteRepositoryInterface;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;

final readonly class FavoritesService implements FavoritesPortInterface
{
    public function __construct(
        private UserFavoriteRepositoryInterface $favoriteRepository,
    ) {
    }

    public function addFavorite(Uuid $userId, FavoriteType $entityType, string $entityPublicId): UserFavorite
    {
        $favorite = UserFavorite::create($userId, $entityType, $entityPublicId);
        $this->favoriteRepository->save($favorite);

        return $favorite;
    }

    public function removeFavorite(UserFavorite $favorite): void
    {
        $this->favoriteRepository->delete($favorite);
    }

    public function findByPublicId(PublicId $publicId): ?UserFavorite
    {
        return $this->favoriteRepository->findByPublicId($publicId);
    }

    public function findByUserAndEntity(Uuid $userId, FavoriteType $entityType, string $entityPublicId): ?UserFavorite
    {
        return $this->favoriteRepository->findByUserAndEntity($userId, $entityType, $entityPublicId);
    }

    public function findByUser(Uuid $userId, ?FavoriteType $entityType = null, int $limit = 50, int $offset = 0): array
    {
        return $this->favoriteRepository->findByUser($userId, $entityType, $limit, $offset);
    }

    public function countByUser(Uuid $userId, ?FavoriteType $entityType = null): int
    {
        return $this->favoriteRepository->countByUser($userId, $entityType);
    }
}
