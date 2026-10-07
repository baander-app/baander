<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Infrastructure\Cache;

use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\ValueObject\ChainId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Infrastructure\Cache\CachedAccessTokenRepository;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Cache\CacheTags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class CachedAccessTokenRepositoryTest extends TestCase
{
    private AccessToken $token;
    private User $user;
    private TagAwareAdapter $cache;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->token = AccessToken::issue(Client::create('Test', ['https://baander.app']), $this->user);
        $this->cache = new TagAwareAdapter(new ArrayAdapter());
    }

    /** @return iterable<string, array{bool|null}> */
    public static function cachedStatuses(): iterable
    {
        yield 'legacy revoked' => [true];
        yield 'legacy active' => [false];
        yield 'warm null' => [null];
    }

    #[DataProvider('cachedStatuses')]
    public function testCachedStatusNeverOverridesDatabaseAggregate(?bool $status): void
    {
        $this->seed($status);
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('findByTokenId')
            ->with($this->token->getTokenId())->willReturn($this->token);

        self::assertSame($this->token, $this->decorator($inner)->findByTokenId($this->token->getTokenId()));
    }

    public function testAbsentTokenIsNotPublishedAsAuthenticationDecision(): void
    {
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('findByTokenId')->willReturn(null);

        self::assertNull($this->decorator($inner)->findByTokenId($this->token->getTokenId()));
        self::assertFalse($this->cache->getItem($this->key())->isHit());
    }

    public function testRevokedDatabaseAggregateIsReturnedForConsumerChecks(): void
    {
        $this->seed(false);
        $this->token->revoke();
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('findByTokenId')->willReturn($this->token);

        self::assertSame($this->token, $this->decorator($inner)->findByTokenId($this->token->getTokenId()));
        self::assertTrue($this->token->isRevoked());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function savedStates(): iterable
    {
        yield 'active flushed' => [false, true];
        yield 'revoked flushed' => [true, true];
        yield 'active deferred' => [false, false];
        yield 'revoked deferred' => [true, false];
    }

    #[DataProvider('savedStates')]
    public function testSaveInvalidatesWarmStatusWithoutPublishingState(bool $revoked, bool $flush): void
    {
        $this->seed(null);
        if ($revoked) {
            $this->token->revoke();
        }
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('save')->with($this->token, $flush);

        $this->decorator($inner)->save($this->token, $flush);

        self::assertFalse($this->cache->getItem($this->key())->isHit());
    }

    public function testDeferredRevocationDoesNotHideIndependentActiveStateAfterRollback(): void
    {
        // Model persistence restoring the same identity; runtime tests cover real DB rollback.
        $active = AccessToken::reconstitute(clone $this->token->getState());
        $this->token->revoke();
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('save')->with($this->token, false);
        $inner->expects($this->once())->method('findByTokenId')->willReturn($active);
        $decorator = $this->decorator($inner);

        $decorator->save($this->token, false);

        self::assertSame($active, $decorator->findByTokenId($active->getTokenId()));
        self::assertFalse($active->isRevoked());
        self::assertFalse($this->cache->getItem($this->key())->isHit());
    }

    public function testCacheOutageCannotAffectDatabaseLookup(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->never())->method('get');
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('findByTokenId')->willReturn($this->token);

        self::assertSame($this->token, $this->decorator($inner, $cache)->findByTokenId($this->token->getTokenId()));
    }

    public function testDatabaseLookupFailureIsNotRetriedOrMasked(): void
    {
        $failure = new \RuntimeException('Database unavailable');
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('findByTokenId')->willThrowException($failure);
        $this->expectExceptionObject($failure);

        $this->decorator($inner)->findByTokenId($this->token->getTokenId());
    }

    public function testDatabaseSaveFailurePreventsCacheInvalidation(): void
    {
        $failure = new \RuntimeException('Database unavailable');
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('save')->willThrowException($failure);
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->never())->method('delete');
        $this->expectExceptionObject($failure);

        $this->decorator($inner, $cache)->save($this->token);
    }

    public function testSaveCacheFailureIsLoggedAfterSuccessfulDatabaseWrite(): void
    {
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('save')->with($this->token, false);
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $failure = new \RuntimeException('Cache unavailable');
        $cache->method('delete')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('invalidate'),
            $this->callback(fn (array $context): bool => $context['exception'] === $failure),
        );

        $this->decorator($inner, $cache, $logger)->save($this->token, false);
    }

    public function testFalseInvalidationResultIsLogged(): void
    {
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('save');
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('delete')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->decorator($inner, $cache, $logger)->save($this->token);
    }

    public function testLoggerFailureDoesNotMaskSuccessfulSave(): void
    {
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('save');
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('delete')->willThrowException(new \RuntimeException('Cache unavailable'));
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('Logger unavailable'));

        $this->decorator($inner, $cache, $logger)->save($this->token);
    }

    /** @return iterable<string, array{string}> */
    public static function bulkMethods(): iterable
    {
        yield 'chain revocation' => ['revokeByChainId'];
        yield 'user revocation' => ['revokeForUser'];
    }

    #[DataProvider('bulkMethods')]
    public function testBulkRevocationInvalidatesLegacyTaggedStatus(string $method): void
    {
        $this->seed(true);
        $argument = $method === 'revokeByChainId' ? ChainId::generate() : $this->user->getId();
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method($method)->with($argument);

        $this->decorator($inner)->{$method}($argument);

        self::assertFalse($this->cache->getItem($this->key())->isHit());
    }

    #[DataProvider('bulkMethods')]
    public function testBulkDatabaseFailureDoesNotInvalidateCache(string $method): void
    {
        $argument = $method === 'revokeByChainId' ? ChainId::generate() : $this->user->getId();
        $failure = new \RuntimeException('Database unavailable');
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method($method)->willThrowException($failure);
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');
        $this->expectExceptionObject($failure);

        $this->decorator($inner, $cache)->{$method}($argument);
    }

    #[DataProvider('bulkMethods')]
    public function testBulkCacheAndLoggerFailureDoNotMaskDatabaseSuccess(string $method): void
    {
        $argument = $method === 'revokeByChainId' ? ChainId::generate() : $this->user->getId();
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method($method)->with($argument);
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('invalidateTags')->willThrowException(new \RuntimeException('Cache unavailable'));
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('Logger unavailable'));

        $this->decorator($inner, $cache, $logger)->{$method}($argument);
    }

    public function testClientRevocationInvalidatesLegacyTaggedStatus(): void
    {
        $this->seed(true);
        $clientId = Uuid::generate();
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('revokeByClientId')->with($clientId);

        $this->decorator($inner)->revokeByClientId($clientId);

        self::assertFalse($this->cache->getItem($this->key())->isHit());
    }

    public function testClientRevocationDatabaseFailureDoesNotInvalidateCache(): void
    {
        $failure = new \RuntimeException('Database unavailable');
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('revokeByClientId')->willThrowException($failure);
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');
        $this->expectExceptionObject($failure);

        $this->decorator($inner, $cache)->revokeByClientId(Uuid::generate());
    }

    public function testClientRevocationCacheFailureIsLoggedAfterDatabaseSuccess(): void
    {
        $clientId = Uuid::generate();
        $inner = $this->createMock(AccessTokenRepositoryInterface::class);
        $inner->expects($this->once())->method('revokeByClientId')->with($clientId);
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('invalidateTags')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('client revocation'),
            $this->callback(fn (array $context): bool => $context['client_id'] === $clientId->toString()),
        );

        $this->decorator($inner, $cache, $logger)->revokeByClientId($clientId);
    }

    private function seed(?bool $status): void
    {
        $this->cache->get($this->key(), static function (ItemInterface $item) use ($status): ?bool {
            $item->tag([CacheTags::OAUTH_TOKEN]);
            $item->expiresAfter(60);
            return $status;
        });
        self::assertTrue($this->cache->getItem($this->key())->isHit());
    }

    private function key(): string
    {
        return 'oauth_revoked_' . $this->token->getTokenId()->toString();
    }

    private function decorator(
        AccessTokenRepositoryInterface $inner,
        ?TagAwareCacheInterface $cache = null,
        ?LoggerInterface $logger = null,
    ): CachedAccessTokenRepository {
        return new CachedAccessTokenRepository($inner, $cache ?? $this->cache, $logger ?? $this->createStub(LoggerInterface::class));
    }
}
