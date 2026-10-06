<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\TokenMetadata;
use App\Auth\Domain\Repository\OAuth\TokenMetadataRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\TokenMetadataEntity;
use Doctrine\ORM\EntityManagerInterface;

final class TokenMetadataRepository implements TokenMetadataRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(TokenMetadata $metadata): void
    {
        // Metadata references the access token's primary key, not its public token identifier.
        $tokenEntity = $this->entityManager
            ->getRepository(AccessTokenEntity::class)
            ->find($metadata->getTokenId());

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

    public function findByTokenId(TokenId $tokenId): ?TokenMetadata
    {
        $entity = $this->findEntityByTokenId($tokenId);
        if ($entity === null) {
            return null;
        }

        return TokenMetadata::reconstitute(
            id: $entity->getId(),
            tokenId: $entity->getToken()->getId(),
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

    public function deleteByTokenId(TokenId $tokenId): void
    {
        $entity = $this->findEntityByTokenId($tokenId);
        if ($entity !== null) {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        }
    }

    /** The public token identifier lives on the access token; metadata references the token's primary key. */
    private function findEntityByTokenId(TokenId $tokenId): ?TokenMetadataEntity
    {
        $entity = $this->entityManager->createQueryBuilder()
            ->select('metadata', 'token')
            ->from(TokenMetadataEntity::class, 'metadata')
            ->innerJoin('metadata.token', 'token')
            ->where('token.tokenId = :tokenId')
            ->setParameter('tokenId', $tokenId->toString())
            ->getQuery()
            ->getOneOrNullResult();

        return $entity instanceof TokenMetadataEntity ? $entity : null;
    }
}
