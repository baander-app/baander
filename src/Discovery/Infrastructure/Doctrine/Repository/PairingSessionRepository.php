<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Doctrine\Repository;

use App\Discovery\Domain\Model\PairingSession;
use App\Discovery\Domain\Model\PairingSessionState;
use App\Discovery\Domain\Repository\PairingSessionRepositoryInterface;
use App\Discovery\Domain\ValueObject\AuthenticationMethod;
use App\Discovery\Domain\ValueObject\PairingCode;
use App\Discovery\Infrastructure\Doctrine\Entity\PairingSessionEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class PairingSessionRepository implements PairingSessionRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PairingSession $session): void
    {
        $entity = $this->findEntityOrCreate($session);
        $this->syncToEntity($session, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(PairingSession $session): void
    {
        $entity = $this->findEntityOrCreate($session);
        $this->syncToEntity($session, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?PairingSession
    {
        $entity = $this->entityManager
            ->getRepository(PairingSessionEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPairingCode(PairingCode $code): ?PairingSession
    {
        $entity = $this->entityManager
            ->getRepository(PairingSessionEntity::class)
            ->findOneBy(['pairingCode' => $code->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findPendingByServer(Uuid $serverId): ?PairingSession
    {
        $qb = $this->entityManager->createQueryBuilder();

        $entity = $qb->select('e')
            ->from(PairingSessionEntity::class, 'e')
            ->where('e.serverId = :serverId')
            ->andWhere('e.completedAt IS NULL')
            ->andWhere('e.expiredAt IS NULL')
            ->setParameter('serverId', $serverId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function delete(PairingSession $session): void
    {
        $entity = $this->entityManager
            ->getRepository(PairingSessionEntity::class)
            ->find($session->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(PairingSession $session): PairingSessionEntity
    {
        $existing = $this->entityManager
            ->getRepository(PairingSessionEntity::class)
            ->find($session->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new PairingSessionEntity(
            $session->getPublicId(),
            $session->getServerId(),
            $session->getServerPublicId(),
            $session->getServerUrl(),
            $session->getServerName(),
            $session->getPairingCode()->toString(),
            $session->getMethod()->value,
            $session->getExpiresAt(),
            id: $session->getId(),
        );
    }

    private function toDomain(PairingSessionEntity $entity): PairingSession
    {
        return PairingSession::reconstitute(new PairingSessionState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            serverId: $entity->getServerId(),
            serverPublicId: $entity->getServerPublicId(),
            serverUrl: $entity->getServerUrl(),
            serverName: $entity->getServerName(),
            pairingCode: PairingCode::fromString($entity->getPairingCode()),
            method: AuthenticationMethod::from($entity->getMethod()),
            expiresAt: $entity->getExpiresAt(),
            createdAt: $entity->getCreatedAt(),
            completedAt: $entity->getCompletedAt(),
            expiredAt: $entity->getExpiredAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(PairingSession $session, PairingSessionEntity $entity): void
    {
        $entity->setCompletedAt($session->getCompletedAt());
        $entity->setExpiredAt($session->getExpiredAt());
    }
}
