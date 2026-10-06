<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine\Repository;

use App\Party\Domain\Model\SyncedPartySession;
use App\Party\Domain\Model\SyncedPartySessionState;
use App\Party\Domain\Repository\SyncedPartySessionRepositoryInterface;
use App\Party\Domain\ValueObject\PlaybackState;
use App\Party\Infrastructure\Doctrine\Entity\SyncedPartySessionEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class SyncedPartySessionRepository implements SyncedPartySessionRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SyncedPartySession $session): void
    {
        $entity = $this->findEntityOrCreate($session);
        $this->syncToEntity($session, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(SyncedPartySession $session): void
    {
        $entity = $this->findEntityOrCreate($session);
        $this->syncToEntity($session, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?SyncedPartySession
    {
        $entity = $this->entityManager
            ->getRepository(SyncedPartySessionEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?SyncedPartySession
    {
        $entity = $this->entityManager
            ->getRepository(SyncedPartySessionEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /** @return SyncedPartySession[] */
    public function findActiveSessions(): array
    {
        $entities = $this->entityManager
            ->getRepository(SyncedPartySessionEntity::class)
            ->findBy(['isActive' => true]);

        return array_map(fn(SyncedPartySessionEntity $e) => $this->toDomain($e), $entities);
    }

    /** @return SyncedPartySession[] */
    public function findByVideo(Uuid $videoId): array
    {
        $entities = $this->entityManager
            ->getRepository(SyncedPartySessionEntity::class)
            ->findBy(['videoId' => $videoId, 'isActive' => true]);

        return array_map(fn(SyncedPartySessionEntity $e) => $this->toDomain($e), $entities);
    }

    public function delete(SyncedPartySession $session): void
    {
        $entity = $this->entityManager
            ->getRepository(SyncedPartySessionEntity::class)
            ->find($session->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(SyncedPartySession $session): SyncedPartySessionEntity
    {
        $existing = $this->entityManager
            ->getRepository(SyncedPartySessionEntity::class)
            ->find($session->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new SyncedPartySessionEntity(
            $session->getPublicId(),
            $session->getHostUserId(),
            $session->getVideoId(),
            $session->getTranscodeJobId(),
            $session->getMaxMembers(),
            id: $session->getId(),
        );
    }

    private function toDomain(SyncedPartySessionEntity $entity): SyncedPartySession
    {
        return SyncedPartySession::reconstitute(new SyncedPartySessionState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            hostUserId: $entity->getHostUserId(),
            videoId: $entity->getVideoId(),
            transcodeJobId: $entity->getTranscodeJobId(),
            maxMembers: $entity->getMaxMembers(),
            playbackState: PlaybackState::from($entity->getPlaybackState()),
            wallClockPosition: $entity->getWallClockPosition(),
            playbackStartedAt: $entity->getPlaybackStartedAt(),
            pausedAtPosition: $entity->getPausedAtPosition(),
            isActive: $entity->isActive(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(SyncedPartySession $session, SyncedPartySessionEntity $entity): void
    {
        $state = $session->getState();
        $entity->setHostUserId($state->hostUserId);
        $entity->setPlaybackState($state->playbackState->value);
        $entity->setWallClockPosition($state->wallClockPosition);
        $entity->setPlaybackStartedAt($state->playbackStartedAt);
        $entity->setPausedAtPosition($state->pausedAtPosition);
        $entity->setIsActive($state->isActive);
    }
}
