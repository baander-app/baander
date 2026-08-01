<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\Credential;

use App\Auth\Domain\Model\Credential\ThirdPartyCredential;
use App\Auth\Domain\Model\Credential\ThirdPartyCredentialState;
use App\Auth\Domain\Repository\Credential\ThirdPartyCredentialRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\ThirdPartyCredentialEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class ThirdPartyCredentialRepository implements ThirdPartyCredentialRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ThirdPartyCredential $credential): void
    {
        $entity = $this->findEntityOrCreate($credential);
        $this->syncToEntity($credential, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByUuid(Uuid $uuid): ?ThirdPartyCredential
    {
        $entity = $this->entityManager
            ->getRepository(ThirdPartyCredentialEntity::class)
            ->find($uuid);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUserAndProvider(Uuid $userId, string $provider): ?ThirdPartyCredential
    {
        $entity = $this->entityManager
            ->getRepository(ThirdPartyCredentialEntity::class)
            ->findOneBy(['user' => $userId, 'provider' => $provider]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /**
     * @return ThirdPartyCredential[]
     */
    public function findByUser(Uuid $userId): array
    {
        $entities = $this->entityManager
            ->getRepository(ThirdPartyCredentialEntity::class)
            ->findBy(['user' => $userId], ['createdAt' => 'DESC']);

        return array_map(fn (ThirdPartyCredentialEntity $entity) => $this->toDomain($entity), $entities);
    }

    public function delete(ThirdPartyCredential $credential): void
    {
        $entity = $this->entityManager
            ->getRepository(ThirdPartyCredentialEntity::class)
            ->find($credential->getId());

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    // --- Internal ---

    /**
     * Convert a ThirdPartyCredentialEntity to a domain ThirdPartyCredential model.
     */
    private function toDomain(ThirdPartyCredentialEntity $entity): ThirdPartyCredential
    {
        return ThirdPartyCredential::reconstitute(new ThirdPartyCredentialState(
            id: $entity->getId(),
            userId: $entity->getUser()->getId(),
            provider: $entity->getProvider(),
            accessToken: $entity->getAccessToken(),
            refreshToken: $entity->getRefreshToken(),
            expiresAt: $entity->getExpiresAt(),
            metadata: $entity->getMeta(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        ));
    }

    private function findEntityOrCreate(ThirdPartyCredential $credential): ThirdPartyCredentialEntity
    {
        $existing = $this->entityManager
            ->getRepository(ThirdPartyCredentialEntity::class)
            ->find($credential->getId());

        if ($existing !== null) {
            return $existing;
        }

        return new ThirdPartyCredentialEntity(
            new \App\Shared\Domain\Model\PublicId(),
            $this->entityManager->getReference(\App\Auth\Infrastructure\Doctrine\Entity\UserEntity::class, $credential->getUserId()->toString()),
            $credential->getProvider(),
            $credential->getAccessToken(),
            $credential->getRefreshToken(),
            $credential->getMetadata(),
            $credential->getExpiresAt(),
            id: $credential->getId(),
        );
    }

    private function syncToEntity(ThirdPartyCredential $credential, ThirdPartyCredentialEntity $entity): void
    {
        $entity->setProvider($credential->getProvider());
        $entity->setAccessToken($credential->getAccessToken());
        $entity->setRefreshToken($credential->getRefreshToken());
        $entity->setExpiresAt($credential->getExpiresAt());
        $entity->setMeta($credential->getMetadata());
    }
}