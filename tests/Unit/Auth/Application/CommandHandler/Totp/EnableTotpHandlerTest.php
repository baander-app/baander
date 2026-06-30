<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Totp\EnableTotpCommand;
use App\Auth\Application\CommandHandler\Totp\EnableTotpHandler;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\CacheItem;

final class EnableTotpHandlerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private TotpVerifierInterface&MockObject $totpVerifier;
    private CacheItemPoolInterface&MockObject $cache;
    private EnableTotpHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->totpVerifier = $this->createMock(TotpVerifierInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->handler = new EnableTotpHandler($this->userRepository, $this->totpVerifier, $this->cache);
    }

    public function testEnablesTotpWithValidCode(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $secret = 'JBSWY3DPEHPK3PXP';

        // Mock the cache to return the pending secret
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($secret);
        $this->cache->method('getItem')->with('totp_pending_secret_' . $user->getId()->toString())->willReturn($cacheItem);

        $this->userRepository->method('findByUuid')->willReturn($user);
        $this->userRepository->expects($this->once())->method('save');
        $this->totpVerifier->method('verifyCode')->with($secret, '123456')->willReturn(true);
        $this->cache->expects($this->once())->method('deleteItem');

        ($this->handler)(new EnableTotpCommand($user->getId()->toString(), '123456'));

        $this->assertSame($secret, $user->getTotpSecret());
    }

    public function testThrowsOnUserNotFound(): void
    {
        $this->userRepository->method('findByUuid')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        ($this->handler)(new EnableTotpCommand(\App\Shared\Domain\Model\Uuid::v4()->toString(), '123456'));
    }

    public function testThrowsOnInvalidCode(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $secret = 'JBSWY3DPEHPK3PXP';

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($secret);
        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->userRepository->method('findByUuid')->willReturn($user);
        $this->totpVerifier->method('verifyCode')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid TOTP code');

        ($this->handler)(new EnableTotpCommand($user->getId()->toString(), '000000'));
    }

    public function testThrowsWhenNoPendingSecretExists(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');

        // Mock cache miss (no pending secret)
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->with('totp_pending_secret_' . $user->getId()->toString())->willReturn($cacheItem);

        $this->userRepository->method('findByUuid')->willReturn($user);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No pending TOTP setup found');

        ($this->handler)(new EnableTotpCommand($user->getId()->toString(), '123456'));
    }

    public function testDoesNotPersistSecretOnInvalidCode(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $secret = 'JBSWY3DPEHPK3PXP';

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($secret);
        $this->cache->method('getItem')->willReturn($cacheItem);

        $this->userRepository->method('findByUuid')->willReturn($user);
        $this->totpVerifier->method('verifyCode')->willReturn(false);
        $this->userRepository->expects($this->never())->method('save');

        try {
            ($this->handler)(new EnableTotpCommand($user->getId()->toString(), '000000'));
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertNull($user->getTotpSecret());
    }

    public function testDeletesPendingSecretAfterSuccessfulEnable(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $secret = 'JBSWY3DPEHPK3PXP';
        $key = 'totp_pending_secret_' . $user->getId()->toString();

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($secret);
        $this->cache->method('getItem')->with($key)->willReturn($cacheItem);

        $this->userRepository->method('findByUuid')->willReturn($user);
        $this->totpVerifier->method('verifyCode')->willReturn(true);
        $this->cache->expects($this->once())->method('deleteItem')->with($key);

        ($this->handler)(new EnableTotpCommand($user->getId()->toString(), '123456'));
    }
}
