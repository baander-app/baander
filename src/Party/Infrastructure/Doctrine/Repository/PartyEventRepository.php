<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine\Repository;

use App\Party\Infrastructure\Doctrine\Entity\PartyEventEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class PartyEventRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PartyEventEntity $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /** @return PartyEventEntity[] */
    public function findBySession(Uuid $sessionId): array
    {
        return $this->entityManager
            ->getRepository(PartyEventEntity::class)
            ->findBy(['sessionId' => $sessionId]);
    }

    public function deleteBySession(Uuid $sessionId): void
    {
        $entities = $this->entityManager
            ->getRepository(PartyEventEntity::class)
            ->findBy(['sessionId' => $sessionId]);

        foreach ($entities as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
    }
}
