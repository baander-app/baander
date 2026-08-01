<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Repository;

use App\Radio\Domain\Model\RadioSource\RadioSource;
use App\Radio\Domain\Model\RadioSource\RadioSourceState;
use App\Radio\Domain\Repository\RadioSource\RadioSourceRepositoryInterface;
use App\Radio\Domain\ValueObject\SyncConfig;
use App\Radio\Infrastructure\Doctrine\Entity\RadioSourceEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class RadioSourceDoctrineRepository implements RadioSourceRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(Uuid $id): ?RadioSource
    {
        $entity = $this->entityManager->find(RadioSourceEntity::class, $id);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findAll(): array
    {
        $entities = $this->entityManager
            ->getRepository(RadioSourceEntity::class)
            ->findAll();

        return array_map($this->toDomain(...), $entities);
    }

    public function findByType(string $type): array
    {
        $entities = $this->entityManager
            ->getRepository(RadioSourceEntity::class)
            ->findBy(['type' => $type]);

        return array_map($this->toDomain(...), $entities);
    }

    public function findActive(): array
    {
        $entities = $this->entityManager
            ->getRepository(RadioSourceEntity::class)
            ->findBy(['isActive' => true]);

        return array_map($this->toDomain(...), $entities);
    }

    public function save(RadioSource $source): void
    {
        $entity = $this->findEntityOrCreate($source);
        $this->syncToEntity($source, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(RadioSource $source): void
    {
        $entity = $this->entityManager->find(RadioSourceEntity::class, $source->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(RadioSource $source): RadioSourceEntity
    {
        $existing = $this->entityManager->find(RadioSourceEntity::class, $source->getId());

        if ($existing !== null) {
            return $existing;
        }

        $state = $source->getState();

        return new RadioSourceEntity(
            id: $state->id,
            name: $state->name,
            type: $state->type,
            syncUrl: $state->syncConfig->syncUrl,
            syncConfig: $state->syncConfig->config,
            syncSchedule: $state->syncConfig->schedule,
        );
    }

    private function syncToEntity(RadioSource $source, RadioSourceEntity $entity): void
    {
        $state = $source->getState();
        $entity->setName($state->name);
        $entity->setType($state->type);
        $entity->setSyncUrl($state->syncConfig->syncUrl);
        $entity->setSyncConfig($state->syncConfig->config);
        $entity->setSyncSchedule($state->syncConfig->schedule);
        $entity->setActive($state->isActive);
        $entity->setUpdatedAt($state->updatedAt);
    }

    private function toDomain(RadioSourceEntity $entity): RadioSource
    {
        return RadioSource::reconstitute(new RadioSourceState(
            id: $entity->getId(),
            name: $entity->getName(),
            type: $entity->getType(),
            syncConfig: new SyncConfig(
                syncUrl: $entity->getSyncUrl(),
                schedule: $entity->getSyncSchedule(),
                config: $entity->getSyncConfig(),
            ),
            isActive: $entity->isActive(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }
}
