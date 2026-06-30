<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Cache;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Shared\Infrastructure\Cache\CacheTags;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Throwable;

/**
 * Decorator that caches token revocation status in Redis.
 *
 * Caches a boolean revocation status separately from the full domain object.
 * The ACL adapter's isAccessTokenRevoked() hot path only needs to know
 * if a token is revoked/expired/not-found — it does not use the full AccessToken.
 */
final readonly class CachedAccessTokenRepository implements AccessTokenRepositoryInterface
{
    private const REVOCATION_TTL = 60;

    public function __construct(
        private readonly AccessTokenRepositoryInterface $inner,
        private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function save(AccessToken $accessToken): void
    {
        $this->inner->save($accessToken);

        $tokenId = $accessToken->getTokenId()->toString();

        if ($accessToken->isRevoked()) {
            // Proactively set cache to true — prevents TOCTOU race where
            // a concurrent request repopulates from DB between delete and next read
            $this->setRevoked($tokenId);
        } else {
            // Token state changed (not a revocation) — clear cache for fresh read
            $this->cache->delete($this->revocationKey($tokenId));
        }
    }

    public function findByTokenId(TokenId $tokenId): ?AccessToken
    {
        $revocationKey = $this->revocationKey($tokenId->toString());

        try {
            $isRevoked = $this->cache->get(
                $revocationKey,
                function (ItemInterface $item): ?bool {
                    $item->tag([
                        CacheTags::oauthToken($item->getKey()),
                        CacheTags::OAUTH_TOKEN,
                    ]);
                    $item->expiresAfter(self::REVOCATION_TTL);

                    return null; // Cache miss — null signals need to check DB
                },
                beta: 1.5,
            );

            // If cache returned true (explicitly cached as revoked), return null
            // The ACL adapter treats null as revoked
            if ($isRevoked === true) {
                return null;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Token revocation cache read failed, falling through to database', [
                'exception' => $e,
                'token_id' => $tokenId->toString(),
            ]);
        }

        return $this->inner->findByTokenId($tokenId);
    }

    public function revokeByChainId(ChainId $chainId): void
    {
        $this->inner->revokeByChainId($chainId);

        try {
            $this->cache->invalidateTags([CacheTags::OAUTH_TOKEN]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to invalidate token cache on chain revocation', [
                'exception' => $e,
                'chain_id' => $chainId->toString(),
            ]);
        }
    }

    public function revokeForUser(User $user): void
    {
        $this->inner->revokeForUser($user);

        try {
            $this->cache->invalidateTags([CacheTags::OAUTH_TOKEN]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to invalidate token cache on user revocation', [
                'exception' => $e,
                'user_id' => $user->getId()->toString(),
            ]);
        }
    }

    private function setRevoked(string $tokenId): void
    {
        $revocationKey = $this->revocationKey($tokenId);

        try {
            $this->cache->get(
                $revocationKey,
                function (ItemInterface $item): bool {
                    $item->tag([
                        CacheTags::oauthToken($item->getKey()),
                        CacheTags::OAUTH_TOKEN,
                    ]);
                    $item->expiresAfter(self::REVOCATION_TTL);

                    return true;
                },
            );
        } catch (Throwable $e) {
            $this->logger->error('Failed to set revoked status in cache', [
                'exception' => $e,
                'token_id' => $tokenId,
            ]);
        }
    }

    private function revocationKey(string $tokenId): string
    {
        return 'oauth_revoked_' . $tokenId;
    }
}
