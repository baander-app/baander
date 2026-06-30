<?php

declare(strict_types=1);

namespace App\Radio\Infrastructure\Doctrine\Repository;

use App\Radio\Domain\Model\RadioSession\RadioSession;
use App\Radio\Domain\Model\RadioSession\RadioSessionState;
use App\Radio\Domain\Repository\RadioSession\RadioSessionRepositoryInterface;
use App\Radio\Infrastructure\Doctrine\Entity\RadioSessionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class RadioSessionDoctrineRepository implements RadioSessionRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function find(Uuid $id): ?RadioSession
    {
        $entity = $this->entityManager->find(RadioSessionEntity::class, $id);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUserId(Uuid $userId): ?RadioSession
    {
        $entity = $this->entityManager
            ->getRepository(RadioSessionEntity::class)
            ->findOneBy(['userId' => $userId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(RadioSession $session): void
    {
        $entity = $this->findEntityOrCreate($session);
        $this->syncToEntity($session, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(RadioSession $session): void
    {
        $entity = $this->entityManager->find(RadioSessionEntity::class, $session->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(RadioSession $session): RadioSessionEntity
    {
        $existing = $this->entityManager->find(RadioSessionEntity::class, $session->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new RadioSessionEntity(
            id: $session->getId(),
            userId: $session->getUserId(),
        );
    }

    private function syncToEntity(RadioSession $session, RadioSessionEntity $entity): void
    {
        $state = $session->getDomainState();
        $entity->setActiveStationId($state->activeStationId);
        $entity->setActiveStreamUrl($state->activeStreamUrl);
        $entity->setState($state->state);
        $entity->setUpdatedAt($state->updatedAt);
    }

    private function toDomain(RadioSessionEntity $entity): RadioSession
    {
        return RadioSession::reconstitute(new RadioSessionState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            activeStationId: $entity->getActiveStationId(),
            activeStreamUrl: $entity->getActiveStreamUrl(),
            state: $entity->getState(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }
}
