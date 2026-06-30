<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Doctrine\Repository;

use App\Discovery\Domain\Model\ServerInstance;
use App\Discovery\Domain\Model\ServerInstanceState;
use App\Discovery\Domain\Repository\ServerInstanceRepositoryInterface;
use App\Discovery\Domain\ValueObject\ServerStatus;
use App\Discovery\Infrastructure\Doctrine\Entity\ServerInstanceEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class ServerInstanceRepository implements ServerInstanceRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ServerInstance $server): void
    {
        $entity = $this->findEntityOrCreate($server);
        $this->syncToEntity($server, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function persist(ServerInstance $server): void
    {
        $entity = $this->findEntityOrCreate($server);
        $this->syncToEntity($server, $entity);
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?ServerInstance
    {
        $entity = $this->entityManager
            ->getRepository(ServerInstanceEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByPublicId(PublicId $publicId): ?ServerInstance
    {
        $entity = $this->entityManager
            ->getRepository(ServerInstanceEntity::class)
            ->findOneBy(['publicId' => $publicId]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByServerUrl(string $serverUrl): ?ServerInstance
    {
        $entity = $this->entityManager
            ->getRepository(ServerInstanceEntity::class)
            ->findOneBy(['serverUrl' => $serverUrl]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /** @return ServerInstance[] */
    public function findStale(int $thresholdSeconds = 300): array
    {
        $cutoff = new \DateTimeImmutable(sprintf('-%d seconds', $thresholdSeconds));
        $qb = $this->entityManager->createQueryBuilder();

        $entities = $qb->select('e')
            ->from(ServerInstanceEntity::class, 'e')
            ->where('e.lastHeartbeatAt < :cutoff OR e.lastHeartbeatAt IS NULL')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        return array_map(fn (ServerInstanceEntity $e) => $this->toDomain($e), $entities);
    }

    public function delete(ServerInstance $server): void
    {
        $entity = $this->entityManager
            ->getRepository(ServerInstanceEntity::class)
            ->find($server->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    private function findEntityOrCreate(ServerInstance $server): ServerInstanceEntity
    {
        $existing = $this->entityManager
            ->getRepository(ServerInstanceEntity::class)
            ->find($server->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new ServerInstanceEntity(
            $server->getPublicId(),
            $server->getServerUrl(),
            $server->getName(),
            $server->getApiKey(),
            $server->getVersion(),
            $server->getStatus()->value,
            id: $server->getId(),
        );
    }

    private function toDomain(ServerInstanceEntity $entity): ServerInstance
    {
        return ServerInstance::reconstitute(new ServerInstanceState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            serverUrl: $entity->getServerUrl(),
            name: $entity->getName(),
            apiKey: $entity->getApiKey(),
            createdAt: $entity->getCreatedAt(),
            version: $entity->getVersion(),
            status: ServerStatus::from($entity->getStatus()),
            lastHeartbeatAt: $entity->getLastHeartbeatAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function syncToEntity(ServerInstance $server, ServerInstanceEntity $entity): void
    {
        $state = $server->getState();
        $entity->setVersion($state->version);
        $entity->setStatus($state->status->value);
        $entity->setLastHeartbeatAt($state->lastHeartbeatAt);
    }
}
