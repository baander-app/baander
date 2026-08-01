<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\TokenMetadataEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;

final class TokenMetadataRepository implements TokenMetadataRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(TokenMetadata $metadata): void
    {
        $tokenEntity = $this->entityManager
            ->getRepository(AccessTokenEntity::class)
            ->findOneBy(['tokenId' => $metadata->getTokenId()->toString()]);

        if ($tokenEntity === null) {
            throw new \RuntimeException('Access token not found for metadata storage.');
        }

        $existing = $this->entityManager
            ->getRepository(TokenMetadataEntity::class)
            ->findOneBy(['token' => $tokenEntity]);

        if ($existing !== null) {
            $existing->setUserAgent($metadata->getUserAgent());
            $existing->setIpAddress($metadata->getIpAddress());
        } else {
            $entity = new TokenMetadataEntity(
                $tokenEntity,
                $metadata->getUserAgent(),
                $metadata->getDeviceOperatingSystem(),
                $metadata->getDeviceName(),
                $metadata->getClientFingerprint(),
                $metadata->getSessionId(),
                $metadata->getIpAddress(),
                $metadata->getCountryCode(),
                $metadata->getCity(),
            );
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    public function findByTokenId(Uuid $tokenId): ?TokenMetadata
    {
        $tokenEntity = $this->entityManager
            ->getRepository(AccessTokenEntity::class)
            ->findOneBy(['tokenId' => $tokenId->toString()]);

        if ($tokenEntity === null) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(TokenMetadataEntity::class)
            ->findOneBy(['token' => $tokenEntity]);

        if ($entity === null) {
            return null;
        }

        return TokenMetadata::reconstitute(
            id: $entity->getId(),
            tokenId: $tokenEntity->getId(),
            userAgent: $entity->getUserAgent(),
            deviceOperatingSystem: $entity->getDeviceOperatingSystem(),
            deviceName: $entity->getDeviceName(),
            clientFingerprint: $entity->getClientFingerprint(),
            sessionId: $entity->getSessionId(),
            ipAddress: $entity->getIpAddress(),
            ipHistory: $entity->getIpHistory(),
            ipChangeCount: $entity->getIpChangeCount(),
            countryCode: $entity->getCountryCode(),
            city: $entity->getCity(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
        );
    }

    public function deleteByTokenId(Uuid $tokenId): void
    {
        $tokenEntity = $this->entityManager
            ->getRepository(AccessTokenEntity::class)
            ->findOneBy(['tokenId' => $tokenId->toString()]);

        if ($tokenEntity === null) {
            return;
        }

        $entity = $this->entityManager
            ->getRepository(TokenMetadataEntity::class)
            ->findOneBy(['token' => $tokenEntity]);

        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }
}
