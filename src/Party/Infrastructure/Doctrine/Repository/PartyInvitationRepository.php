<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine\Repository;

use App\Party\Domain\Model\PartyInvitation;
use App\Party\Domain\Model\PartyInvitationState;
use App\Party\Domain\Repository\PartyInvitationRepositoryInterface;
use App\Party\Infrastructure\Doctrine\Entity\PartyInvitationEntity;
use App\Party\Infrastructure\Doctrine\Entity\SyncedPartySessionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class PartyInvitationRepository implements PartyInvitationRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PartyInvitation $invitation): void
    {
        if ($this->entityManager->find(PartyInvitationEntity::class, $invitation->getId()) !== null) {
            return;
        }

        $this->entityManager->persist(new PartyInvitationEntity(
            $invitation->getId(),
            $invitation->getUserId(),
            $this->entityManager->getReference(SyncedPartySessionEntity::class, $invitation->getSessionId()),
            $invitation->getCreatedAt(),
        ));
        $this->entityManager->flush();
    }

    public function findByUserAndSession(Uuid $userId, Uuid $sessionId): ?PartyInvitation
    {
        $entity = $this->entityManager
            ->getRepository(PartyInvitationEntity::class)
            ->findOneBy(['userId' => $userId, 'session' => $sessionId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function existsForUserAndSession(Uuid $userId, Uuid $sessionId): bool
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(invitation.id) FROM ' . PartyInvitationEntity::class . ' invitation'
            . ' WHERE invitation.userId = :user AND invitation.session = :session',
        )
            ->setParameter('user', $userId, 'uuid')
            ->setParameter('session', $sessionId, 'uuid')
            ->getSingleScalarResult() > 0;
    }

    /** @return list<PartyInvitation> */
    public function findBySession(Uuid $sessionId): array
    {
        /** @var list<PartyInvitationEntity> $entities */
        $entities = $this->entityManager->createQuery(
            'SELECT invitation FROM ' . PartyInvitationEntity::class . ' invitation'
            . ' WHERE invitation.session = :session'
            . ' ORDER BY invitation.createdAt ASC, invitation.id ASC',
        )
            ->setParameter('session', $sessionId, 'uuid')
            ->getResult();

        return array_map($this->toDomain(...), $entities);
    }

    public function delete(PartyInvitation $invitation): void
    {
        $entity = $this->entityManager->find(PartyInvitationEntity::class, $invitation->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function toDomain(PartyInvitationEntity $entity): PartyInvitation
    {
        return PartyInvitation::reconstitute(new PartyInvitationState(
            id: $entity->getId(),
            userId: $entity->getUserId(),
            sessionId: $entity->getSessionId(),
            createdAt: $entity->getCreatedAt(),
        ));
    }
}
