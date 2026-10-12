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
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
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

    public function save(RefreshToken $refreshToken, bool $flush = true): void
    {
        $entity = $this->findEntityOrCreate($refreshToken);
        $this->syncToEntity($refreshToken, $entity);
        $this->entityManager->persist($entity);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findByTokenId(TokenId $tokenId): ?RefreshToken
    {
        $entity = $this->entityManager
            ->getRepository(RefreshTokenEntity::class)
            ->findOneBy(['tokenId' => $tokenId->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function consumeByTokenId(TokenId $tokenId): ?RefreshToken
    {
        $conn = $this->entityManager->getConnection();
        $rowsAffected = (int) $conn->executeStatement(
            <<<'SQL'
                UPDATE oauth_refresh_tokens
                SET used_at = NOW(), updated_at = NOW()
                WHERE token_id = :tokenId
                  AND used_at IS NULL
                  AND revoked = FALSE
                  AND (expires_at IS NULL OR expires_at > NOW())
                SQL,
            ['tokenId' => $tokenId->toString()],
        );

        if ($rowsAffected === 0) {
            return null;
        }

        $entity = $this->entityManager
            ->getRepository(RefreshTokenEntity::class)
            ->findOneBy(['tokenId' => $tokenId->toString()]);

        if ($entity === null) {
            return null;
        }

        $this->entityManager->refresh($entity);

        return $this->toDomain($entity);
    }

    public function lockByTokenId(TokenId $tokenId): ?RefreshToken
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT id FROM oauth_refresh_tokens WHERE token_id = :tokenId FOR UPDATE',
            ['tokenId' => $tokenId->toString()],
        );
        if (!is_string($id)) {
            return null;
        }

        return $this->toDomain($this->lockedEntity(Uuid::fromString($id)));
    }

    /**
     * @return list<RefreshToken>
     */
    public function lockSuccessors(Uuid $refreshTokenId): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM oauth_refresh_tokens WHERE previous_refresh_token_id = :id ORDER BY id FOR UPDATE',
            ['id' => $refreshTokenId->toString()],
        );

        return array_map(
            fn (string $id): RefreshToken => $this->toDomain($this->lockedEntity(Uuid::fromString($id))),
            $ids,
        );
    }

    public function consumedWithin(Uuid $refreshTokenId, int $seconds): bool
    {
        // The lost-response grace window: one predicate on the database clock, with >=.
        $answer = $this->entityManager->getConnection()->fetchOne(
            'SELECT (used_at >= NOW() - make_interval(secs => :seconds))::int FROM oauth_refresh_tokens WHERE id = :id',
            ['id' => $refreshTokenId->toString(), 'seconds' => $seconds],
            ['seconds' => ParameterType::INTEGER],
        );

        return (int) $answer === 1;
    }

    public function chainHoldsLiveToken(ChainId $chainId): bool
    {
        $answer = $this->entityManager->getConnection()->fetchOne(
            'SELECT (EXISTS (SELECT 1 FROM oauth_refresh_tokens WHERE chain_id = :chainId AND revoked = FALSE))::int',
            ['chainId' => $chainId->toString()],
        );

        return (int) $answer === 1;
    }

    /**
     * @param list<Uuid> $ids
     */
    public function revokeByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE oauth_refresh_tokens SET revoked = TRUE, updated_at = NOW() WHERE id IN (:ids) AND revoked = FALSE',
            ['ids' => array_map(static fn (Uuid $id): string => $id->toString(), $ids)],
            ['ids' => ArrayParameterType::STRING],
        );
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
        // Lock the chain's rows in id order first: a grace locks a token before its
        // later-issued successors, so both sides take the rows in the same order.
        $conn->fetchFirstColumn(
            'SELECT id FROM oauth_refresh_tokens WHERE chain_id = :chainId ORDER BY id FOR UPDATE',
            ['chainId' => $chainId->toString()],
        );
        $conn->executeStatement(
            'UPDATE oauth_refresh_tokens SET revoked = TRUE, updated_at = NOW() WHERE chain_id = :chainId AND revoked = FALSE',
            ['chainId' => $chainId->toString()],
        );
        $this->entityManager->clear();
    }

    public function revokeForUser(Uuid $userId, ?AccessToken $keep = null): void
    {
        $sql = <<<'SQL'
            UPDATE oauth_refresh_tokens r SET revoked = TRUE, updated_at = NOW()
              FROM oauth_access_tokens a
             WHERE a.id = r.access_token_id AND a.user_id = :userId AND r.revoked = FALSE
            SQL;
        $params = ['userId' => $userId->toString()];
        if ($keep !== null) {
            $sql .= ' AND r.access_token_id <> :keepId';
            $params['keepId'] = $keep->getId()->toString();
            if ($keep->getChainId() !== null) {
                $sql .= ' AND r.chain_id IS DISTINCT FROM :keepChainId';
                $params['keepChainId'] = $keep->getChainId()->toString();
            }
        }

        $this->entityManager->getConnection()->executeStatement($sql, $params);
        $this->entityManager->clear();
    }

    public function revokeByClientId(Uuid $clientId): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            <<<'SQL'
                UPDATE oauth_refresh_tokens AS refresh_token
                SET revoked = TRUE, updated_at = NOW()
                FROM oauth_access_tokens AS access_token
                WHERE refresh_token.access_token_id = access_token.id
                  AND access_token.client_id = :clientId
                  AND refresh_token.revoked = FALSE
                SQL,
            ['clientId' => $clientId->toString()],
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

    /**
     * The row as it is under the lock just taken. The identity map may hold the row as it
     * was read before the transaction, and the lock may have waited for another session's
     * commit, so the entity and its access token are re-read.
     */
    private function lockedEntity(Uuid $id): RefreshTokenEntity
    {
        $entity = $this->entityManager->getRepository(RefreshTokenEntity::class)->find($id);
        if ($entity === null) {
            throw new \LogicException(sprintf('Refresh token row %s vanished under its lock.', $id->toString()));
        }
        $this->entityManager->refresh($entity);
        $this->entityManager->refresh($entity->getAccessToken());

        return $entity;
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
                roles: $entity->getUser()->getRoles(),
                disabled: $entity->getUser()->isDisabled(),
            ))
            : null;

        $client = Client::reconstitute(new ClientState(
            id: $entity->getClient()->getId(),
            publicId: $entity->getClient()->getPublicId(),
            name: $entity->getClient()->getName(),
            secretHash: $entity->getClient()->getSecretHash(),
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
            dpopJkt: $entity->getDpopJkt(),
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
