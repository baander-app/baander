<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Adapter\OAuth;

use App\Auth\Domain\Model\OAuth\RefreshToken;
use App\Auth\Domain\Model\OAuth\RefreshTokenState;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Shared\Domain\Model\Uuid;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface as DomainRepository;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\AccessTokenEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\ClientEntity;
use App\Auth\Infrastructure\Doctrine\Entity\OAuth\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * Anti-corruption layer adapting the league/oauth2-server RefreshTokenRepositoryInterface
 * to our domain repository.
 */
final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly AccessTokenRepositoryInterface $accessTokenRepository,
    ) {
    }

    public function getNewRefreshToken(): RefreshTokenEntity
    {
        return new RefreshTokenEntity(
            bin2hex(random_bytes(40)),
            $this->createStubAccessTokenEntity(),
        );
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $entity = $this->ensureRefreshTokenEntity($refreshTokenEntity);

        $accessTokenEntity = $entity->getAccessToken();
        $accessTokenDomain = $this->accessTokenRepository->findByTokenId(
            TokenId::fromString($accessTokenEntity->getTokenId()),
        );

        if ($accessTokenDomain === null) {
            throw new \RuntimeException('Access token not found for refresh token.');
        }

        $chainId = $entity->getChainId() !== null
            ? ChainId::fromUuid($entity->getChainId())
            : ChainId::generate();

        $domain = RefreshToken::reconstitute(new RefreshTokenState(
            id: Uuid::generate(),
            tokenId: TokenId::fromString($entity->getTokenId()),
            accessToken: $accessTokenDomain,
            chainId: $chainId,
            previousRefreshToken: null,
            expiresAt: $entity->getExpiresAt(),
            usedAt: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            revoked: false,
        ));

        $this->domainRepository->save($domain);
    }

    public function revokeRefreshToken($tokenId): void
    {
        $domain = $this->domainRepository->findByTokenId(TokenId::fromString($tokenId));

        if ($domain !== null) {
            $domain->revoke();
            $this->domainRepository->save($domain);
        }
    }

    public function isRefreshTokenRevoked($tokenId): bool
    {
        $domain = $this->domainRepository->findByTokenId(TokenId::fromString($tokenId));

        if ($domain === null) {
            return true;
        }

        if ($domain->isRevoked()) {
            return true;
        }

        if ($domain->isExpired()) {
            return true;
        }

        return false;
    }

    // --- Internal ---

    private function ensureRefreshTokenEntity(RefreshTokenEntityInterface $entity): RefreshTokenEntity
    {
        if ($entity instanceof RefreshTokenEntity) {
            return $entity;
        }

        throw new \RuntimeException(sprintf(
            'Expected %s, got %s.',
            RefreshTokenEntity::class,
            get_debug_type($entity),
        ));
    }

    private function createStubAccessTokenEntity(): AccessTokenEntity
    {
        return new class(bin2hex(random_bytes(40)), new class() extends ClientEntity {
            public function __construct()
            {
            }

            public function getIdentifier(): string
            {
                return 'stub-client';
            }

            public function getRedirectUri(): string|array
            {
                return '';
            }
        }) extends AccessTokenEntity {
        };
    }
}
