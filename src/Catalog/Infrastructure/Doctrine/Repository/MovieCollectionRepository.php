<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Doctrine\Repository;

use App\Catalog\Infrastructure\Doctrine\Entity\MovieCollectionEntity;
use Doctrine\ORM\EntityManagerInterface;

final class MovieCollectionRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByTmdbCollectionId(int $tmdbCollectionId): ?MovieCollectionEntity
    {
        return $this->entityManager
            ->getRepository(MovieCollectionEntity::class)
            ->findOneBy(['tmdbCollectionId' => $tmdbCollectionId]);
    }

    public function save(MovieCollectionEntity $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findOrCreate(int $tmdbCollectionId, string $name): MovieCollectionEntity
    {
        $existing = $this->findByTmdbCollectionId($tmdbCollectionId);
        if ($existing !== null) {
            return $existing;
        }

        $entity = new MovieCollectionEntity($tmdbCollectionId, $name);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }
}
