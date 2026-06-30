<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Doctrine\Repository;

use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Transcode\Domain\Model\TranscodeSession;
use App\Transcode\Domain\Model\TranscodeSessionState;
use App\Transcode\Domain\Repository\TranscodeSessionRepositoryInterface;
use App\Transcode\Domain\ValueObject\AudioProfile;
use App\Transcode\Domain\ValueObject\SessionPriority;
use App\Transcode\Domain\ValueObject\SessionState;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeJobEntity;
use App\Transcode\Infrastructure\Doctrine\Entity\TranscodeSessionEntity;
use Doctrine\ORM\EntityManagerInterface;

final class TranscodeSessionRepository implements TranscodeSessionRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(TranscodeSession $session): void
    {
        $entity = $this->findEntityOrCreate($session);
        $this->syncToEntity($session, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(TranscodeSession $session): void
    {
        $entity = $this->findEntityOrCreate($session);
        $this->syncToEntity($session, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?TranscodeSession
    {
        $entity = $this->entityManager
            ->getRepository(TranscodeSessionEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?TranscodeSession
    {
        $entity = $this->entityManager
            ->getRepository(TranscodeSessionEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /** @return TranscodeSession[] */
    public function findByUser(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(TranscodeSessionEntity::class)
            ->findBy(['userId' => $userId]);

        return array_map(fn(TranscodeSessionEntity $e) => $this->toDomain($e), $entities);
    }

    /** @return TranscodeSession[] */
    public function findByJob(Uuid $jobId): array
    {
        $jobEntity = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->find($jobId);

        if ($jobEntity === null) {
            return [];
        }

        $entities = $this->entityManager
            ->getRepository(TranscodeSessionEntity::class)
            ->findBy(['job' => $jobEntity]);

        return array_map(fn(TranscodeSessionEntity $e) => $this->toDomain($e), $entities);
    }

    /** @return TranscodeSession[] */
    public function findActiveSessions(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(TranscodeSessionEntity::class)
            ->findBy([
                'userId' => $userId,
                'state' => [SessionState::Pending->value, SessionState::Preparing->value, SessionState::Active->value, SessionState::Paused->value],
            ]);

        return array_map(fn(TranscodeSessionEntity $e) => $this->toDomain($e), $entities);
    }

    public function count(): int
    {
        return (int) $this->entityManager
            ->getRepository(TranscodeSessionEntity::class)
            ->count([]);
    }

    public function delete(TranscodeSession $session): void
    {
        $entity = $this->entityManager
            ->getRepository(TranscodeSessionEntity::class)
            ->find($session->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    private function findEntityOrCreate(TranscodeSession $session): TranscodeSessionEntity
    {
        $existing = $this->entityManager
            ->getRepository(TranscodeSessionEntity::class)
            ->find($session->getId());

        if ($existing !== null) {
            return $existing;
        }

        $jobEntity = $this->entityManager
            ->getRepository(TranscodeJobEntity::class)
            ->find($session->getJobId());

        if ($jobEntity === null) {
            throw new \RuntimeException(sprintf(
                'TranscodeJobEntity not found for jobId: %s',
                $session->getJobId()->toString(),
            ));
        }

        return new TranscodeSessionEntity(
            $session->getPublicId(),
            $session->getUserId(),
            $jobEntity,
            $session->getVideoId(),
            $session->getSessionState()->value,
            $session->getPriority()->value,
            $session->getAudioProfile()->jsonSerialize(),
            id: $session->getId(),
        );
    }

    private function toDomain(TranscodeSessionEntity $entity): TranscodeSession
    {
        $audioProfileData = $entity->getAudioProfile();

        $audioProfile = AudioProfile::fromString($audioProfileData['name'] ?? 'streaming_stereo');

        return TranscodeSession::reconstitute(new TranscodeSessionState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            userId: $entity->getUserId(),
            jobId: $entity->getJobId(),
            videoId: $entity->getVideoId(),
            state: SessionState::from($entity->getState()),
            priority: SessionPriority::from($entity->getPriority()),
            audioProfile: $audioProfile,
            currentSegmentIndex: $entity->getCurrentSegmentIndex(),
            wallClockOffset: $entity->getWallClockOffset(),
            metrics: $entity->getMetrics(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(TranscodeSession $session, TranscodeSessionEntity $entity): void
    {
        $entity->setState($session->getSessionState()->value);
        $entity->setPriority($session->getPriority()->value);
        $entity->setAudioProfile($session->getAudioProfile()->jsonSerialize());
        $entity->setCurrentSegmentIndex($session->getCurrentSegmentIndex());
        $entity->setWallClockOffset($session->getWallClockOffset());
        $entity->setMetrics($session->getMetrics());
    }
}
