<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AuthCode;
use App\Auth\Domain\Model\OAuth\AuthCodeState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AuthCodeRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AuthCodeEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Pure domain repository for authorization codes.
 */
final class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function save(AuthCode $authCode): void
    {
        $entity = $this->findEntityOrCreate($authCode);
        $this->syncToEntity($authCode, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByCodeId(TokenId $codeId): ?AuthCode
    {
        $entity = $this->entityManager
            ->getRepository(AuthCodeEntity::class)
            ->findOneBy(['codeId' => $codeId->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    // --- Internal ---

    private function findEntityOrCreate(AuthCode $authCode): AuthCodeEntity
    {
        $existing = $this->entityManager
            ->getRepository(AuthCodeEntity::class)
            ->find($authCode->getId());

        if ($existing !== null) {
            return $existing;
        }

        $clientEntity = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->find($authCode->getClient()->getId());

        $userEntity = $this->entityManager
            ->getRepository(UserEntity::class)
            ->find($authCode->getUser()->getId());

        return new AuthCodeEntity(
            $authCode->getCodeId()->toString(),
            $userEntity,
            $clientEntity,
            $authCode->getScopes() !== [] ? $authCode->getScopeIdentifiers() : null,
            $authCode->getExpiresAt(),
            id: $authCode->getId(),
        );
    }

    private function toDomain(AuthCodeEntity $entity): AuthCode
    {
        return AuthCode::reconstitute(new AuthCodeState(
            id: $entity->getId(),
            codeId: TokenId::fromString($entity->getCodeId()),
            user: $this->userEntityToDomain($entity->getUser()),
            client: $this->clientEntityToDomain($entity->getClient()),
            scopes: array_map(
                fn (string $scopeId) => new Scope($scopeId),
                $entity->getScopeIdentifiers() ?? [],
            ),
            expiresAt: $entity->getExpiresAt(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            revoked: $entity->isRevoked(),
        ));
    }

    private function syncToEntity(AuthCode $authCode, AuthCodeEntity $entity): void
    {
        if ($authCode->isRevoked()) {
            $entity->revoke();
        }
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
