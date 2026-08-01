<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Cache;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Infrastructure\Cache\CachedAccessTokenRepository;
use App\Shared\Domain\Model\Email;
use App\Shared\Infrastructure\Cache\CacheTags;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class CachedAccessTokenRepositoryTest extends TestCase
{
    private TagAwareCacheInterface&MockObject $cache;
    private AccessTokenRepositoryInterface&MockObject $inner;
    private LoggerInterface&MockObject $logger;
    private CachedAccessTokenRepository $decorator;

    private static Client $client;
    private static User $user;
    private static AccessToken $activeToken;
    private static AccessToken $revokedToken;

    /** 40-char hex token ID (matches bin2hex(random_bytes(40))) */
    private const TOKEN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TOKEN_ID_2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        self::$client ??= Client::create('Test', ['http://localhost']);
        self::$user ??= User::register(new Email('test@example.com'), 'hashed', 'Alice');
        self::$activeToken ??= AccessToken::issue(self::$client, self::$user, [new \App\Auth\Domain\Model\OAuth\ValueObject\Scope('read')]);

        $active = AccessToken::issue(self::$client, self::$user, [new \App\Auth\Domain\Model\OAuth\ValueObject\Scope('read')]);
        $active->revoke();
        self::$revokedToken = $active;

        $this->cache = $this->createMock(TagAwareCacheInterface::class);
        $this->inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->decorator = new CachedAccessTokenRepository(
            $this->inner,
            $this->cache,
            $this->logger,
        );
    }

    // --- findByTokenId: cache miss delegates to DB ---

    public function testFindByTokenIdCacheMissDelegatesToDatabase(): void
    {
        $tokenId = TokenId::fromString(self::TOKEN_ID);

        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                return $callback($this->createMock(ItemInterface::class));
            });

        $this->inner->expects($this->once())
            ->method('findByTokenId')
            ->with($tokenId)
            ->willReturn(self::$activeToken);

        $result = $this->decorator->findByTokenId($tokenId);

        $this->assertSame(self::$activeToken, $result);
    }

    public function testFindByTokenIdCacheMissReturnsDomainObject(): void
    {
        $tokenId = TokenId::fromString(self::TOKEN_ID);

        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                return null; // Cache miss
            });

        $this->inner->expects($this->once())
            ->method('findByTokenId')
            ->with($tokenId)
            ->willReturn(self::$activeToken);

        $result = $this->decorator->findByTokenId($tokenId);

        $this->assertSame(self::$activeToken, $result);
    }

    public function testFindByTokenIdCacheHitRevokedReturnsNull(): void
    {
        $tokenId = TokenId::fromString(self::TOKEN_ID_2);

        // Cache returns true (revoked)
        $this->cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                return true;
            });

        // Should NOT call database
        $this->inner->expects($this->never())
            ->method('findByTokenId');

        $result = $this->decorator->findByTokenId($tokenId);

        $this->assertNull($result);
    }

    // --- findByTokenId: error handling ---

    public function testFindByTokenIdCacheFailureFallsThroughToDatabase(): void
    {
        $tokenId = TokenId::fromString(self::TOKEN_ID);

        $this->cache->method('get')
            ->willThrowException(new \RuntimeException('Redis connection refused'));

        $this->logger->expects($this->once())
            ->method('warning');

        $this->inner->expects($this->once())
            ->method('findByTokenId')
            ->with($tokenId)
            ->willReturn(self::$activeToken);

        $result = $this->decorator->findByTokenId($tokenId);

        $this->assertSame(self::$activeToken, $result);
    }

    // --- save: revocation detection ---

    public function testSaveWithRevokedTokenSetsCacheToTrue(): void
    {
        $this->inner->expects($this->once())
            ->method('save')
            ->with(self::$revokedToken);

        // Cache should be written with true
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                return true; // Will be set during save
            });

        $this->decorator->save(self::$revokedToken);
    }

    public function testSaveWithActiveTokenDeletesCacheEntry(): void
    {
        $this->inner->expects($this->once())
            ->method('save')
            ->with(self::$activeToken);

        $this->cache->expects($this->once())
            ->method('delete')
            ->with($this->stringContains('oauth_revoked_'));

        $this->decorator->save(self::$activeToken);
    }

    // --- revokeByChainId: tag invalidation ---

    public function testRevokeByChainIdInvalidatesAllTokenCaches(): void
    {
        $chainId = ChainId::generate();

        $this->inner->expects($this->once())
            ->method('revokeByChainId')
            ->with($chainId);

        $this->cache->expects($this->once())
            ->method('invalidateTags')
            ->with([CacheTags::OAUTH_TOKEN]);

        $this->decorator->revokeByChainId($chainId);
    }

    public function testRevokeByChainIdLogsErrorOnInvalidationFailure(): void
    {
        $chainId = ChainId::generate();

        $this->inner->expects($this->once())
            ->method('revokeByChainId');

        $this->cache->method('invalidateTags')
            ->willThrowException(new \RuntimeException('Lua script error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to invalidate'));

        $this->decorator->revokeByChainId($chainId);
    }

    // --- revokeForUser: tag invalidation ---

    public function testRevokeForUserInvalidatesAllTokenCaches(): void
    {
        $this->inner->expects($this->once())
            ->method('revokeForUser')
            ->with(self::$user);

        $this->cache->expects($this->once())
            ->method('invalidateTags');

        $this->decorator->revokeForUser(self::$user);
    }

    // --- save: error handling ---

    public function testSaveWithRevokedTokenLogsErrorOnCacheFailure(): void
    {
        $this->inner->expects($this->once())
            ->method('save')
            ->with(self::$revokedToken);

        $this->cache->method('get')
            ->willThrowException(new \RuntimeException('Redis down'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to set revoked'));

        $this->decorator->save(self::$revokedToken);
    }
}
