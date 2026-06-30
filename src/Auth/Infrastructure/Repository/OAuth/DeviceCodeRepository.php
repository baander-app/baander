<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientState;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\OAuth\DeviceCodeState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\DeviceCodeEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Pure domain repository for device codes.
 */
final class DeviceCodeRepository implements DeviceCodeRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function save(DeviceCode $deviceCode): void
    {
        $entity = $this->findEntityOrCreate($deviceCode);
        $this->syncToEntity($deviceCode, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findById(Uuid $id): ?DeviceCode
    {
        $entity = $this->entityManager
            ->getRepository(DeviceCodeEntity::class)
            ->find($id);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByDeviceCode(TokenId $deviceCode): ?DeviceCode
    {
        $entity = $this->entityManager
            ->getRepository(DeviceCodeEntity::class)
            ->findOneBy(['deviceCode' => $deviceCode->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findByUserCode(string $userCode): ?DeviceCode
    {
        $entity = $this->entityManager
            ->getRepository(DeviceCodeEntity::class)
            ->findOneBy(['userCode' => $userCode]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    // --- Internal ---

    private function findEntityOrCreate(DeviceCode $deviceCode): DeviceCodeEntity
    {
        $existing = $this->entityManager
            ->getRepository(DeviceCodeEntity::class)
            ->find($deviceCode->getId());

        if ($existing !== null) {
            return $existing;
        }

        $clientEntity = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->find($deviceCode->getClient()->getId());

        $entity = new DeviceCodeEntity(
            $deviceCode->getDeviceCode()->toString(),
            $deviceCode->getUserCode(),
            $clientEntity,
            $deviceCode->getVerificationUri(),
            $deviceCode->getVerificationUriComplete(),
            $deviceCode->getScopes() !== [] ? $deviceCode->getScopeIdentifiers() : null,
            $deviceCode->getExpiresAt(),
            $deviceCode->getInterval(),
            id: $deviceCode->getId(),
        );

        if ($deviceCode->getUser() !== null) {
            $userEntity = $this->entityManager
                ->getRepository(UserEntity::class)
                ->find($deviceCode->getUser()->getId());

            if ($userEntity !== null) {
                $entity->setUser($userEntity);
            }
        }

        return $entity;
    }

    private function toDomain(DeviceCodeEntity $entity): DeviceCode
    {
        $user = $entity->getUser() !== null
            ? $this->userEntityToDomain($entity->getUser())
            : null;

        return DeviceCode::reconstitute(new DeviceCodeState(
            id: $entity->getId(),
            deviceCode: TokenId::fromString($entity->getDeviceCode()),
            userCode: $entity->getUserCode(),
            user: $user,
            client: $this->clientEntityToDomain($entity->getClient()),
            scopes: array_map(
                fn (string $scopeId) => new Scope($scopeId),
                $entity->getScopeIdentifiers() ?? [],
            ),
            verificationUri: $entity->getVerificationUri(),
            verificationUriComplete: $entity->getVerificationUriComplete() !== '' ? $entity->getVerificationUriComplete() : null,
            expiresAt: $entity->getExpiresAt(),
            interval: $entity->getInterval(),
            lastPolledAt: $entity->getLastPolledAt(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            approved: $entity->isApproved(),
            denied: $entity->isDenied(),
            consumedAt: $entity->getConsumedAt(),
        ));
    }

    private function syncToEntity(DeviceCode $deviceCode, DeviceCodeEntity $entity): void
    {
        if ($deviceCode->isApproved() && !$entity->isApproved()) {
            $entity->approve();
        }

        if ($deviceCode->isDenied() && !$entity->isDenied()) {
            $entity->deny();
        }

        if ($deviceCode->isConsumed() && $entity->getConsumedAt() === null) {
            $entity->consume();
        }

        if ($deviceCode->getUser() !== null) {
            $userEntity = $this->entityManager
                ->getRepository(UserEntity::class)
                ->find($deviceCode->getUser()->getId());

            if ($userEntity !== null) {
                $entity->setUser($userEntity);
            }
        }
    }

    /**
     * Attempt to atomically consume a device code using a conditional UPDATE.
     *
     * Returns true if the device code was successfully consumed (was approved and not yet consumed).
     * Returns false if the device code was already consumed or not approved.
     */
    public function consumeSafely(DeviceCode $deviceCode): bool
    {
        $rowsAffected = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE oauth_device_codes
                SET consumed_at = NOW(), updated_at = NOW()
                WHERE id = :id
                  AND approved = TRUE
                  AND consumed_at IS NULL
                SQL,
            ['id' => $deviceCode->getId()->toString()],
        );

        return $rowsAffected > 0;
    }

    private function userEntityToDomain(UserEntity $entity): User
    {
        return User::reconstitute(new UserState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            email: $entity->getEmail(),
            password: $entity->getPassword(),
            totpSecret: $entity->getTotpSecret(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            emailVerifiedAt: $entity->getEmailVerifiedAt(),
            roles: ['ROLE_USER'],
        ));
    }

    private function clientEntityToDomain(ClientEntity $entity): Client
    {
        return Client::reconstitute(new ClientState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            secret: $entity->getSecret(),
            redirectUris: $this->parseRedirectUris($entity->getRedirect()),
            personalAccessClient: $entity->isPersonalAccessClient(),
            passwordClient: $entity->isPasswordClient(),
            deviceClient: $entity->isDeviceClient(),
            confidential: $entity->isConfidential(),
            firstParty: $entity->isFirstParty(),
            userId: $entity->getUserId(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            revoked: $entity->isRevoked(),
        ));
    }

    /**
     * @return string[]
     */
    private function parseRedirectUris(string $redirect): array
    {
        $uris = $this->jsonEncoder->decode($redirect, 'json');

        if (is_array($uris)) {
            return $uris;
        }

        return array_filter(array_map('trim', explode(',', $redirect)));
    }
}
