<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Repository;

use App\Radio\Domain\Model\StarredStation\StarredStation;
use App\Radio\Domain\Model\StarredStation\StarredStationState;
use App\Radio\Domain\Repository\StarredStation\StarredStationRepositoryInterface;
use App\Radio\Infrastructure\Doctrine\Entity\StarredStationEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class StarredStationDoctrineRepository implements StarredStationRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(Uuid $id): ?StarredStation
    {
        $entity = $this->entityManager->find(StarredStationEntity::class, $id);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUserId(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(StarredStationEntity::class)
            ->findBy(['userId' => $userId]);

        return array_map($this->toDomain(...), $entities);
    }

    public function findByUserIdAndStationId(Uuid $userId, Uuid $stationId): ?StarredStation
    {
        $entity = $this->entityManager
            ->getRepository(StarredStationEntity::class)
            ->findOneBy(['userId' => $userId, 'stationId' => $stationId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(StarredStation $starred): void
    {
        $entity = $this->findEntityOrCreate($starred);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(StarredStation $starred): void
    {
        $entity = $this->entityManager->find(StarredStationEntity::class, $starred->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(StarredStation $starred): StarredStationEntity
    {
        $existing = $this->entityManager->find(StarredStationEntity::class, $starred->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new StarredStationEntity(
            id: $starred->getId(),
            userId: $starred->getUserId(),
            stationId: $starred->getStationId(),
        );
    }

    private function toDomain(StarredStationEntity $entity): StarredStation
    {
        return StarredStation::reconstitute(new StarredStationState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            stationId: $entity->getStationId(),
            starredAt: $entity->getStarredAt(),
        ));
    }
}
