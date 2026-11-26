<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Repository\OAuth;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\AccessTokenState;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ClientState;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\TokenIssuance;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\OAuth\ValueObject\Scope;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Auth\Infrastructure\Doctrine\UserEntityMapping;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Pure domain repository for access tokens.
 */
final class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    /** The longest parameterised IN list one findTokenIdsOfLiveChains() query carries. */
    private const int LIVE_TOKEN_IDS_CHUNK = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JsonEncoder $jsonEncoder,
    ) {
    }

    public function save(AccessToken $accessToken, bool $flush = true): void
    {
        $entity = $this->findEntityOrCreate($accessToken);
        $this->syncToEntity($accessToken, $entity);
        $this->entityManager->persist($entity);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    public function findByTokenId(TokenId $tokenId): ?AccessToken
    {
        $entity = $this->entityManager
            ->getRepository(AccessTokenEntity::class)
            ->findOneBy(['tokenId' => $tokenId->toString()]);

        return $entity !== null ? $this->toDomain($entity) : null;
    }

    public function findTokenIdsByChainId(ChainId $chainId): array
    {
        /** @var list<string> $tokenIds */
        $tokenIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT token_id FROM oauth_access_tokens WHERE chain_id = :chainId ORDER BY id',
            ['chainId' => $chainId->toString()],
        );

        return $tokenIds;
    }

    /**
     * @param list<string> $tokenIds
     *
     * @return list<string>
     */
    public function findTokenIdsOfLiveChains(array $tokenIds): array
    {
        $live = [];
        foreach (array_chunk($tokenIds, self::LIVE_TOKEN_IDS_CHUNK) as $chunk) {
            /** @var list<string> $found */
            $found = $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT a.token_id FROM oauth_access_tokens a JOIN users account ON account.id = a.user_id WHERE a.token_id IN (:ids) AND NOT account.disabled AND EXISTS (SELECT 1 FROM oauth_access_tokens live WHERE live.chain_id = a.chain_id AND live.revoked = FALSE AND (live.expires_at IS NULL OR live.expires_at > NOW()))',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::STRING],
            );
            $live[] = $found;
        }

        return array_merge(...$live);
    }

    public function revokeByChainId(ChainId $chainId): void
    {
        $conn = $this->entityManager->getConnection();
        // Lock the chain's rows in id order first, as the refresh token repository does.
        $conn->fetchFirstColumn(
            'SELECT id FROM oauth_access_tokens WHERE chain_id = :chainId ORDER BY id FOR UPDATE',
            ['chainId' => $chainId->toString()],
        );
        $conn->executeStatement(
            'UPDATE oauth_access_tokens SET revoked = TRUE, updated_at = NOW() WHERE chain_id = :chainId AND revoked = FALSE',
            ['chainId' => $chainId->toString()],
        );
        $this->entityManager->clear();
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
            'UPDATE oauth_access_tokens SET revoked = TRUE, updated_at = NOW() WHERE id IN (:ids) AND revoked = FALSE',
            ['ids' => array_map(static fn (Uuid $id): string => $id->toString(), $ids)],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    public function revokeForUser(Uuid $userId, ?AccessToken $keep = null): array
    {
        $kept = '';
        $params = ['userId' => $userId->toString()];
        if ($keep !== null) {
            $kept = ' AND id <> :keepId';
            $params['keepId'] = $keep->getId()->toString();
            if ($keep->getChainId() !== null) {
                $kept .= ' AND chain_id IS DISTINCT FROM :keepChainId';
                $params['keepChainId'] = $keep->getChainId()->toString();
            }
        }

        // SQL, because DQL has no RETURNING: one statement revokes the tokens and lists every
        // token of the chains it revoked. The outer SELECT reads the rows as they were before
        // the UPDATE, which changes no column it reads.
        /** @var list<string> $tokenIds */
        $tokenIds = $this->entityManager->getConnection()->fetchFirstColumn(
            <<<SQL
                WITH revoked AS (
                    UPDATE oauth_access_tokens
                       SET revoked = TRUE, updated_at = NOW()
                     WHERE user_id = :userId AND revoked = FALSE{$kept}
                 RETURNING id, chain_id
                )
                SELECT token_id
                  FROM oauth_access_tokens
                 WHERE user_id = :userId
                   AND (id IN (SELECT id FROM revoked) OR chain_id IN (SELECT chain_id FROM revoked))
                 ORDER BY id
                SQL,
            $params,
        );
        $this->entityManager->clear();

        return $tokenIds;
    }

    public function isSessionCurrent(AccessToken $token, int $credentialEpoch): bool
    {
        // Scalar results, so a token the entity manager already holds cannot answer from the
        // state it was loaded with.
        $live = $this->entityManager->createQuery(
            'SELECT COUNT(live.id) FROM ' . AccessTokenEntity::class . ' live, ' . AccessTokenEntity::class . ' token
              WHERE token.id = :token
                AND token.credentialEpoch >= :credentialEpoch
                AND live.revoked = false
                AND (live.id = token.id OR live.chainId = token.chainId)',
        )
            ->setParameter('token', $token->getId(), 'uuid')
            ->setParameter('credentialEpoch', $credentialEpoch, ParameterType::INTEGER)
            ->getSingleScalarResult();

        return (int) $live > 0;
    }

    public function currentIssuance(TokenId $tokenId): ?TokenIssuance
    {
        // One indexed read per authenticated request. A token without a user has no epoch to
        // fall behind.
        $row = $this->entityManager->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT a.grant_type, a.credential_epoch
                  FROM oauth_access_tokens a
                  LEFT JOIN users u ON u.id = a.user_id
                 WHERE a.token_id = :tokenId
                   AND (u.id IS NULL OR a.credential_epoch >= u.credential_epoch)
                SQL,
            ['tokenId' => $tokenId->toString()],
        );
        if ($row === false) {
            return null;
        }

        return new TokenIssuance(
            grant: SignInGrant::from((string) $row['grant_type']),
            credentialEpoch: (int) $row['credential_epoch'],
        );
    }

    public function restampCredentialEpoch(AccessToken $keep, int $credentialEpoch): void
    {
        $sql = 'UPDATE oauth_access_tokens SET credential_epoch = :epoch, updated_at = NOW() WHERE id = :keepId';
        $params = ['epoch' => $credentialEpoch, 'keepId' => $keep->getId()->toString()];
        if ($keep->getChainId() !== null) {
            $sql .= ' OR chain_id = :keepChainId';
            $params['keepChainId'] = $keep->getChainId()->toString();
        }

        $this->entityManager->getConnection()->executeStatement($sql, $params, ['epoch' => ParameterType::INTEGER]);
    }

    public function revokeByClientId(Uuid $clientId): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'UPDATE oauth_access_tokens SET revoked = TRUE, updated_at = NOW() WHERE client_id = :clientId AND revoked = FALSE',
            ['clientId' => $clientId->toString()],
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
            $accessToken->getGrant(),
            $userEntity,
            $accessToken->getName(),
            $accessToken->getScopes() !== [] ? $accessToken->getScopeIdentifiers() : null,
            $accessToken->getExpiresAt(),
            $accessToken->getChainId()?->getUuid(),
            id: $accessToken->getId(),
            credentialEpoch: $accessToken->getCredentialEpoch(),
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
            grant: $entity->getGrantType(),
            name: $entity->getName(),
            scopes: array_map(fn (string $s) => new Scope($s), $entity->getScopeIdentifiers() ?? []),
            chainId: $entity->getChainId() !== null ? ChainId::fromUuid($entity->getChainId()) : null,
            expiresAt: $entity->getExpiresAt(),
            lastRefreshedAt: $entity->getLastRefreshedAt(),
            createdAt: $entity->getCreatedAt(),
            updatedAt: $entity->getUpdatedAt(),
            revoked: $entity->isRevoked(),
            dpopJkt: $entity->getDpopJkt(),
            credentialEpoch: $entity->getCredentialEpoch(),
        ));
    }

    private function syncToEntity(AccessToken $accessToken, AccessTokenEntity $entity): void
    {
        $entity->setName($accessToken->getName());
        $entity->setDpopJkt($accessToken->getDpopJkt());
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
        return UserEntityMapping::toDomain($entity);
    }

    private function clientEntityToDomain(ClientEntity $entity): Client
    {
        return Client::reconstitute(new ClientState(
            id: $entity->getId(),
            publicId: $entity->getPublicId(),
            name: $entity->getName(),
            secretHash: $entity->getSecretHash(),
            authMethod: $entity->getTokenEndpointAuthMethod(),
            keySource: $entity->getKeySource(),
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
            : array_map('trim', explode(',', $redirect)) |> array_filter(...);
    }
}
