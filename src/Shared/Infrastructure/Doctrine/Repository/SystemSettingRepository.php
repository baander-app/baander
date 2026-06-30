<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Repository;

use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Infrastructure\Doctrine\Entity\SystemSettingEntity;
use Doctrine\ORM\EntityManagerInterface;

final class SystemSettingRepository implements SystemSettingsPortInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entity = $this->findEntity($key);

        return $entity !== null ? $entity->getValue() : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $entity = $this->findEntity($key);

        if ($entity !== null) {
            $entity->setValue($value);
        } else {
            $entity = new SystemSettingEntity($key, $value);
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function all(): array
    {
        $entities = $this->entityManager
            ->getRepository(SystemSettingEntity::class)
            ->findAll();

        $result = [];
        foreach ($entities as $entity) {
            $result[$entity->getKey()] = $entity->getValue();
        }

        return $result;
    }

    public function remove(string $key): void
    {
        $entity = $this->findEntity($key);

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntity(string $key): ?SystemSettingEntity
    {
        return $this->entityManager
            ->getRepository(SystemSettingEntity::class)
            ->find($key);
    }
}
