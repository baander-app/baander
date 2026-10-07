<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure\Cache;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Cache\CacheTags;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Throwable;

/**
 * Keeps token lookups authoritative in the database.
 *
 * A repository flush can run inside an outer transaction, so cache publication
 * could deny an active token after rollback. Legacy status entries are ignored;
 * invalidation remains best effort for workers running the previous decorator.
 */
final readonly class CachedAccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private readonly AccessTokenRepositoryInterface $inner,
        private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function save(AccessToken $accessToken, bool $flush = true): void
    {
        $this->inner->save($accessToken, $flush);

        $tokenId = $accessToken->getTokenId()->toString();
        $this->invalidateCache(
            fn (): bool => $this->cache->delete($this->revocationKey($tokenId)),
            'Failed to invalidate token cache on save',
            ['token_id' => $tokenId],
        );
    }

    public function findByTokenId(TokenId $tokenId): ?AccessToken
    {
        // Even cached true can describe a revocation that later rolled back.
        // Consumers need the actual aggregate for revocation and expiry checks.
        return $this->inner->findByTokenId($tokenId);
    }

    public function revokeByChainId(ChainId $chainId): void
    {
        $this->inner->revokeByChainId($chainId);

        $this->invalidateCache(
            fn (): bool => $this->cache->invalidateTags([CacheTags::OAUTH_TOKEN]),
            'Failed to invalidate token cache on chain revocation',
            ['chain_id' => $chainId->toString()],
        );
    }

    public function revokeForUser(Uuid $userId, ?AccessToken $keep = null): void
    {
        $this->inner->revokeForUser($userId, $keep);

        $this->invalidateCache(
            fn (): bool => $this->cache->invalidateTags([CacheTags::OAUTH_TOKEN]),
            'Failed to invalidate token cache on user revocation',
            ['user_id' => $userId->toString()],
        );
    }

    /**
     * @param callable(): bool $operation
     * @param array<string, mixed> $context
     */
    private function invalidateCache(callable $operation, string $message, array $context): void
    {
        try {
            if ($operation()) {
                return;
            }
        } catch (Throwable $exception) {
            $context['exception'] = $exception;
        }

        try {
            $this->logger->error($message, $context);
        } catch (Throwable) {
            // Optional cache/logging availability must not mask a successful write.
        }
    }

    private function revocationKey(string $tokenId): string
    {
        return 'oauth_revoked_' . $tokenId;
    }
}
