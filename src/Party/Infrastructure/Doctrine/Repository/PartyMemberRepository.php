<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine\Repository;

use App\Party\Domain\Model\PartyMember;
use App\Party\Domain\Model\PartyMemberState;
use App\Party\Domain\Repository\PartyMemberRepositoryInterface;
use App\Party\Domain\ValueObject\MemberRole;
use App\Party\Infrastructure\Doctrine\Entity\PartyMemberEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class PartyMemberRepository implements PartyMemberRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PartyMember $member): void
    {
        $entity = $this->findEntityOrCreate($member);
        $this->syncToEntity($member, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?PartyMember
    {
        $entity = $this->entityManager
            ->getRepository(PartyMemberEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUserAndSession(Uuid $userId, Uuid $sessionId): ?PartyMember
    {
        $entity = $this->entityManager
            ->getRepository(PartyMemberEntity::class)
            ->findOneBy(['userId' => $userId, 'sessionId' => $sessionId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /** @return PartyMember[] */
    public function findBySession(Uuid $sessionId): array
    {
        $entities = $this->entityManager
            ->getRepository(PartyMemberEntity::class)
            ->findBy(['sessionId' => $sessionId]);

        return array_map(fn(PartyMemberEntity $e) => $this->toDomain($e), $entities);
    }

    public function countBySession(Uuid $sessionId): int
    {
        return (int) $this->entityManager
            ->getRepository(PartyMemberEntity::class)
            ->count(['sessionId' => $sessionId]);
    }

    public function delete(PartyMember $member): void
    {
        $entity = $this->entityManager
            ->getRepository(PartyMemberEntity::class)
            ->find($member->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(PartyMember $member): PartyMemberEntity
    {
        $existing = $this->entityManager
            ->getRepository(PartyMemberEntity::class)
            ->find($member->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new PartyMemberEntity(
            $member->getPublicId(),
            $member->getUserId(),
            $member->getSessionId(),
            $member->getRole()->value,
            id: $member->getId(),
        );
    }

    private function toDomain(PartyMemberEntity $entity): PartyMember
    {
        return PartyMember::reconstitute(new PartyMemberState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            userId: $entity->getUserId(),
            sessionId: $entity->getSessionId(),
            joinedAt: $entity->getJoinedAt(),
            role: MemberRole::from($entity->getRole()),
            audioProfileId: $entity->getAudioProfileId(),
            subtitleTrackId: $entity->getSubtitleTrackId(),
            lastSyncPosition: $entity->getLastSyncPosition(),
            lastSyncAt: $entity->getLastSyncAt(),
            jitterCompensation: $entity->getJitterCompensation(),
            isConnected: $entity->isConnected(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(PartyMember $member, PartyMemberEntity $entity): void
    {
        $state = $member->getState();
        $entity->setRole($state->role->value);
        $entity->setAudioProfileId($state->audioProfileId);
        $entity->setSubtitleTrackId($state->subtitleTrackId);
        $entity->setLastSyncPosition($state->lastSyncPosition);
        $entity->setLastSyncAt($state->lastSyncAt);
        $entity->setJitterCompensation($state->jitterCompensation);
        $entity->setIsConnected($state->isConnected);
    }
}
