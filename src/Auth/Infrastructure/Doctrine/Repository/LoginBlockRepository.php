<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Doctrine\Repository;

use App\Auth\Domain\Model\LoginBlock;
use App\Auth\Domain\Repository\LoginBlockRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\LoginBlockEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class LoginBlockRepository implements LoginBlockRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function save(LoginBlock $block): void
    {
        $entity = new LoginBlockEntity(
            id: $block->getId(),
            ipAddress: $block->getIpAddress(),
            email: $block->getEmail(),
            fieldValue: $block->getFieldValue(),
            userAgent: $block->getUserAgent(),
            createdAt: $block->getCreatedAt(),
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findRecent(int $limit = 50, int $offset = 0): array
    {
        $entities = $this->entityManager
            ->getRepository(LoginBlockEntity::class)
            ->findBy([], ['createdAt' => 'DESC'], $limit, $offset);

        return array_map(fn (LoginBlockEntity $e) => $e->toDomain(), $entities);
    }

    public function countRecent(): int
    {
        return (int) $this->entityManager
            ->getRepository(LoginBlockEntity::class)
            ->count([]);
    }

    public function deleteByUuid(Uuid $uuid): void
    {
        $entity = $this->entityManager->find(LoginBlockEntity::class, $uuid);
        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    public function deleteAll(): void
    {
        $this->entityManager
            ->createQuery('DELETE FROM ' . LoginBlockEntity::class)
            ->execute();
    }
}
