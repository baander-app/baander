<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\AccessTokenState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientState;
use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\RefreshTokenState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\RefreshTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Pure domain repository for refresh tokens.
 */
final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function save(RefreshToken $refreshToken): void
    {
        $entity = $this->findEntityOrCreate($refreshToken);
        $this->syncToEntity($refreshToken, $entity);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function findByTokenId(TokenId $tokenId): ?RefreshToken
    {
        $entity = $this->entityManager
            ->getRepository(RefreshTokenEntity::class)
            ->findOneBy(['tokenId' => $tokenId->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    /**
     * @return RefreshToken[]
     */
    public function findByChainId(ChainId $chainId): array
    {
        return array_map(
            fn (RefreshTokenEntity $e) => $this->toDomain($e),
            $this->findEntitiesByChainId($chainId),
        );
    }

    public function revokeByChainId(ChainId $chainId): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'UPDATE oauth_refresh_tokens SET revoked = TRUE, updated_at = NOW() WHERE chain_id = :chainId AND revoked = FALSE',
            ['chainId' => $chainId->toString()],
        );
        $this->entityManager->clear();
    }

    // --- Internal ---

    /**
     * @return RefreshTokenEntity[]
     */
    private function findEntitiesByChainId(ChainId $chainId): array
    {
        return $this->entityManager
            ->getRepository(RefreshTokenEntity::class)
            ->findBy(['chainId' => $chainId->getUuid()]);
    }

    private function findEntityOrCreate(RefreshToken $refreshToken): RefreshTokenEntity
    {
        $existing = $this->entityManager
            ->getRepository(RefreshTokenEntity::class)
            ->find($refreshToken->getId());

        if ($existing !== null) {
            return $existing;
        }

        $accessTokenEntity = $this->entityManager
            ->getRepository(AccessTokenEntity::class)
            ->find($refreshToken->getAccessToken()->getId());

        $previousEntity = null;
        if ($refreshToken->getPreviousRefreshToken() !== null) {
            $previousEntity = $this->entityManager
                ->getRepository(RefreshTokenEntity::class)
                ->find($refreshToken->getPreviousRefreshToken()->getId());
        }

        return new RefreshTokenEntity(
            $refreshToken->getTokenId()->toString(),
            $accessTokenEntity,
            $refreshToken->getExpiresAt(),
            $refreshToken->getChainId()?->getUuid(),
            $previousEntity,
            id: $refreshToken->getId(),
        );
    }

    private function toDomain(RefreshTokenEntity $entity): RefreshToken
    {
        $accessToken = $this->accessTokenEntityToDomain($entity->getAccessToken());

        $chainId = $entity->getChainId() !== null
            ? ChainId::fromUuid($entity->getChainId())
            : null;

        $previousRefreshToken = $this->loadPredecessor($entity);

        return RefreshToken::reconstitute(new RefreshTokenState(
            id: $entity->getId(),
            tokenId: TokenId::fromString($entity->getTokenId()),
            accessToken: $accessToken,
            chainId: $chainId,
            previousRefreshToken: $previousRefreshToken,
            expiresAt: $entity->getExpiresAt(),
            usedAt: $entity->getUsedAt(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            revoked: $entity->isRevoked(),
        ));
    }

    /**
     * Load the immediate predecessor (one level deep) without recursion.
     *
     * This avoids unbounded memory growth from recursively loading the entire
     * chain while still providing TokenChainValidator with the predecessor
     * state needed for replay detection.
     */
    private function loadPredecessor(RefreshTokenEntity $entity): ?RefreshToken
    {
        $previousEntity = $entity->getPreviousRefreshToken();
        if ($previousEntity === null) {
            return null;
        }

        // Load the predecessor fresh from the DB by its ID (one query only).
        // We do NOT recurse into its own predecessor.
        $fresh = $this->entityManager
            ->getRepository(RefreshTokenEntity::class)
            ->find($previousEntity->getId());

        if ($fresh === null) {
            return null;
        }

        $previousChainId = $fresh->getChainId() !== null
            ? ChainId::fromUuid($fresh->getChainId())
            : null;

        $previousAccessToken = $this->accessTokenEntityToDomain($fresh->getAccessToken());

        return RefreshToken::reconstitute(new RefreshTokenState(
            id: $fresh->getId(),
            tokenId: TokenId::fromString($fresh->getTokenId()),
            accessToken: $previousAccessToken,
            chainId: $previousChainId,
            previousRefreshToken: null, // Do NOT recurse -- stop at one level
            expiresAt: $fresh->getExpiresAt(),
            usedAt: $fresh->getUsedAt(),
            createdAt: $fresh->getCreatedAt(),
            updatedAt: $fresh->getUpdatedAt(),
            revoked: $fresh->isRevoked(),
        ));
    }

    private function syncToEntity(RefreshToken $refreshToken, RefreshTokenEntity $entity): void
    {
        if ($refreshToken->isRevoked()) {
            $entity->revoke();
        }

        if ($refreshToken->hasBeenUsed()) {
            $entity->markUsed();
        }
    }

    private function accessTokenEntityToDomain(AccessTokenEntity $entity): AccessToken
    {
        $user = $entity->getUser() !== null
            ? User::reconstitute(new UserState(
                id: $entity->getUser()->getId(),
                publicId: $entity->getUser()->getPublicId(),
                name: $entity->getUser()->getName(),
                email: $entity->getUser()->getEmail(),
                password: $entity->getUser()->getPassword(),
                totpSecret: $entity->getUser()->getTotpSecret(),
                createdAt: $entity->getUser()->getCreatedAt(),
                updatedAt: $entity->getUser()->getUpdatedAt(),
                emailVerifiedAt: $entity->getUser()->getEmailVerifiedAt(),
                roles: ['ROLE_USER'],
            ))
            : null;

        $client = Client::reconstitute(new ClientState(
            id: $entity->getClient()->getId(),
            publicId: $entity->getClient()->getPublicId(),
            name: $entity->getClient()->getName(),
            secret: $entity->getClient()->getSecret(),
            redirectUris: $this->parseRedirectUris($entity->getClient()->getRedirect()),
            personalAccessClient: $entity->getClient()->isPersonalAccessClient(),
            passwordClient: $entity->getClient()->isPasswordClient(),
            deviceClient: $entity->getClient()->isDeviceClient(),
            confidential: $entity->getClient()->isConfidential(),
            firstParty: $entity->getClient()->isFirstParty(),
            userId: $entity->getClient()->getUserId(),
            createdAt: $entity->getClient()->getCreatedAt(),
            updatedAt: $entity->getClient()->getUpdatedAt(),
            revoked: $entity->getClient()->isRevoked(),
        ));

        $scopes = array_map(
            fn (string $scopeId) => new Scope($scopeId),
            $entity->getScopeIdentifiers() ?? [],
        );

        $chainId = $entity->getChainId() !== null
            ? ChainId::fromUuid($entity->getChainId())
            : null;

        return AccessToken::reconstitute(new AccessTokenState(
            id: $entity->getId(),
            tokenId: TokenId::fromString($entity->getTokenId()),
            user: $user,
            client: $client,
            name: $entity->getName(),
            scopes: $scopes,
            chainId: $chainId,
            expiresAt: $entity->getExpiresAt(),
            lastRefreshedAt: $entity->getLastRefreshedAt(),
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
