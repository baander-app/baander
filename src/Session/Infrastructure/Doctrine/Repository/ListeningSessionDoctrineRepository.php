<?php

declare(strict_types=1);

namespace App\Session\Infrastructure\Doctrine\Repository;

use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\ListeningSession\ListeningSessionState;
use App\Session\Domain\Repository\ListeningSession\ListeningSessionRepositoryInterface;
use App\Session\Infrastructure\Doctrine\Entity\ListeningSessionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class ListeningSessionDoctrineRepository implements ListeningSessionRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function findByUserId(Uuid $userId): ?ListeningSession
    {
        $entity = $this->entityManager
            ->getRepository(ListeningSessionEntity::class)
            ->findOneBy(['userId' => $userId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function save(ListeningSession $session): void
    {
        $entity = $this->findEntityOrCreate($session);
        $this->syncToEntity($session, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function remove(ListeningSession $session): void
    {
        $entity = $this->entityManager
            ->getRepository(ListeningSessionEntity::class)
            ->find($session->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(ListeningSession $session): ListeningSessionEntity
    {
        $existing = $this->entityManager
            ->getRepository(ListeningSessionEntity::class)
            ->find($session->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new ListeningSessionEntity(
            userId: $session->getUserId(),
            queue: $session->getQueue(),
            currentTrackIndex: $session->getCurrentTrackIndex(),
            position: $session->getPosition(),
            id: $session->getId(),
        );
    }

    private function toDomain(ListeningSessionEntity $entity): ListeningSession
    {
        return ListeningSession::reconstitute(new ListeningSessionState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            activeDeviceId: $entity->getActiveDeviceId(),
            queue: $entity->getQueue(),
            currentTrackIndex: $entity->getCurrentTrackIndex(),
            position: $entity->getPosition(),
            playbackState: $entity->getPlaybackState(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            lastUsedAt: $entity->getLastUsedAt(),
        ));
    }

    private function syncToEntity(ListeningSession $session, ListeningSessionEntity $entity): void
    {
        $state = $session->getState();
        $entity->setActiveDeviceId($state->activeDeviceId);
        $entity->setQueue($state->queue);
        $entity->setCurrentTrackIndex($state->currentTrackIndex);
        $entity->setPosition($state->position);
        $entity->setPlaybackState($state->playbackState);
        $entity->setUpdatedAt($state->updatedAt);
        $entity->setLastUsedAt($state->lastUsedAt);
    }
}
