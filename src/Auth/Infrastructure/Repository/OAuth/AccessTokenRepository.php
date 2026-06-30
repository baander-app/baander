<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\AccessTokenState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Pure domain repository for access tokens.
 */
final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function save(AccessToken $accessToken): void
    {
        $entity = $this->findEntityOrCreate($accessToken);
        $this->syncToEntity($accessToken, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByTokenId(TokenId $tokenId): ?AccessToken
    {
        $entity = $this->entityManager
            ->getRepository(AccessTokenEntity::class)
            ->findOneBy(['tokenId' => $tokenId->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function revokeByChainId(ChainId $chainId): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'UPDATE oauth_access_tokens SET revoked = TRUE, updated_at = NOW() WHERE chain_id = :chainId AND revoked = FALSE',
            ['chainId' => $chainId->toString()],
        );
        $this->entityManager->clear();
    }

    public function revokeForUser(User $user): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'UPDATE oauth_access_tokens SET revoked = TRUE, updated_at = NOW() WHERE user_id = :userId AND revoked = FALSE',
            ['userId' => $user->getId()->toString()],
        );
        $this->entityManager->clear();
    }

    // --- Internal ---

    private function findEntityOrCreate(AccessToken $accessToken): AccessTokenEntity
    {
        $existing = $this->entityManager
            ->getRepository(AccessTokenEntity::class)
            ->find($accessToken->getId());

        if ($existing !== null) {
            return $existing;
        }

        $clientEntity = $this->entityManager
            ->getRepository(ClientEntity::class)
            ->find($accessToken->getClient()->getId());

        $userEntity = $accessToken->getUser() !== null
            ? $this->entityManager->getRepository(UserEntity::class)->find($accessToken->getUser()->getId())
            : null;

        return new AccessTokenEntity(
            $accessToken->getTokenId()->toString(),
            $clientEntity,
            $userEntity,
            $accessToken->getName(),
            $accessToken->getScopes() !== [] ? $accessToken->getScopeIdentifiers() : null,
            $accessToken->getExpiresAt(),
            $accessToken->getChainId()?->getUuid(),
            id: $accessToken->getId(),
        );
    }

    private function toDomain(AccessTokenEntity $entity): AccessToken
    {
        $user = $entity->getUser() !== null
            ? $this->userEntityToDomain($entity->getUser())
            : null;

        return AccessToken::reconstitute(new AccessTokenState(
            id: $entity->getId(),
            tokenId: TokenId::fromString($entity->getTokenId()),
            user: $user,
            client: $this->clientEntityToDomain($entity->getClient()),
            name: $entity->getName(),
            scopes: array_map(fn (string $s) => new Scope($s), $entity->getScopeIdentifiers() ?? []),
            chainId: $entity->getChainId() !== null ? ChainId::fromUuid($entity->getChainId()) : null,
            expiresAt: $entity->getExpiresAt(),
            lastRefreshedAt: $entity->getLastRefreshedAt(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            revoked: $entity->isRevoked(),
        ));
    }

    private function syncToEntity(AccessToken $accessToken, AccessTokenEntity $entity): void
    {
        $entity->setName($accessToken->getName());
        $entity->setScopes($accessToken->getScopes() !== []
            ? $accessToken->getScopeIdentifiers()
            : null);

        if ($accessToken->isRevoked()) {
            $entity->revoke();
        }

        if ($accessToken->getLastRefreshedAt() !== null) {
            $entity->markRefreshed();
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

        return is_array($uris) ? $uris
            : array_filter(array_map('trim', explode(',', $redirect)));
    }
}
