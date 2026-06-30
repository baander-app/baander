<?php

declare(strict_types=1);

namespace App\Favorites\Infrastructure\Doctrine\Repository;

use App\Favorites\Domain\Model\UserFavorite;
use App\Favorites\Domain\Model\UserFavoriteState;
use App\Favorites\Domain\Repository\UserFavoriteRepositoryInterface;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Favorites\Infrastructure\Doctrine\Entity\UserFavoriteEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class UserFavoriteRepository implements UserFavoriteRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(UserFavorite $favorite): void
    {
        $entity = $this->findEntityOrCreate($favorite);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?UserFavorite
    {
        $entity = $this->entityManager
            ->getRepository(UserFavoriteEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?UserFavorite
    {
        $entity = $this->entityManager
            ->getRepository(UserFavoriteEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUserAndEntity(Uuid $userId, FavoriteType $entityType, string $entityPublicId): ?UserFavorite
    {
        $entity = $this->entityManager
            ->getRepository(UserFavoriteEntity::class)
            ->findOneBy([
                'userId' => $userId,
                'entityType' => $entityType->value,
                'entityPublicId' => $entityPublicId,
            ]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUser(Uuid $userId, ?FavoriteType $entityType = null, int $limit = 50, int $offset = 0): array
    {
        $criteria = ['userId' => $userId];
        if ($entityType !== null) {
            $criteria['entityType'] = $entityType->value;
        }

        $entities = $this->entityManager
            ->getRepository(UserFavoriteEntity::class)
            ->findBy($criteria, ['createdAt' => 'DESC'], $limit, $offset);

        return array_map(fn(UserFavoriteEntity $e) => $this->toDomain($e), $entities);
    }

    public function countByUser(Uuid $userId, ?FavoriteType $entityType = null): int
    {
        $criteria = ['userId' => $userId];
        if ($entityType !== null) {
            $criteria['entityType'] = $entityType->value;
        }

        return (int) $this->entityManager
            ->getRepository(UserFavoriteEntity::class)
            ->count($criteria);
    }

    public function delete(UserFavorite $favorite): void
    {
        $entity = $this->entityManager
            ->getRepository(UserFavoriteEntity::class)
            ->find($favorite->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(UserFavorite $favorite): UserFavoriteEntity
    {
        $existing = $this->entityManager
            ->getRepository(UserFavoriteEntity::class)
            ->find($favorite->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new UserFavoriteEntity(
            $favorite->getPublicId(),
            $favorite->getUserId(),
            $favorite->getEntityType()->value,
            $favorite->getEntityPublicId(),
            id: $favorite->getId(),
        );
    }

    private function toDomain(UserFavoriteEntity $entity): UserFavorite
    {
        return UserFavorite::reconstitute(new UserFavoriteState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            userId: $entity->getUserId(),
            entityType: FavoriteType::from($entity->getEntityType()),
            entityPublicId: $entity->getEntityPublicId(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }
}
