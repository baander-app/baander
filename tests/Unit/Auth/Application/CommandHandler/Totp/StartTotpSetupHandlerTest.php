<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Totp\StartTotpSetupCommand;
use App\Auth\Application\CommandHandler\Totp\StartTotpSetupHandler;
use App\Auth\Application\Exception\TotpChangeRefusal;
use App\Auth\Application\Exception\TotpChangeRefusedException;
use App\Auth\Application\Port\TotpVerifierInterface;
use App\Auth\Application\Service\PendingTotpSecrets;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class StartTotpSetupHandlerTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
    }

    public function testEachSetupReplacesThePendingSecret(): void
    {
        $pending = new PendingTotpSecrets(new ArrayAdapter());
        $handler = $this->handler($pending, ['FIRSTSECRET', 'SECONDSECRET']);

        $first = $handler(new StartTotpSetupCommand($this->user->getId()->toString()));
        $second = $handler(new StartTotpSetupCommand($this->user->getId()->toString()));

        $this->assertSame(['FIRSTSECRET', 'SECONDSECRET'], [$first, $second]);
        $this->assertSame('SECONDSECRET', $pending->find($this->user->getId()));
    }

    public function testThePendingSecretExpiresAfterTenMinutes(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->expects($this->once())->method('set')->with('FIRSTSECRET')->willReturnSelf();
        $item->expects($this->once())->method('expiresAfter')->with(600)->willReturnSelf();
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $pool->expects($this->once())->method('save')->with($item)->willReturn(true);

        $this->handler(new PendingTotpSecrets($pool), ['FIRSTSECRET'])(new StartTotpSetupCommand($this->user->getId()->toString()));
    }

    /** TOTP is turned off first, which takes a current code. */
    public function testWhileTotpIsOnSetupIsRefused(): void
    {
        $this->user->enableTotp('sealed-secret');
        $pending = new PendingTotpSecrets(new ArrayAdapter());

        try {
            $this->handler($pending, ['FIRSTSECRET'])(new StartTotpSetupCommand($this->user->getId()->toString()));
            self::fail('Setup must be refused while TOTP is on.');
        } catch (TotpChangeRefusedException $exception) {
            $this->assertSame(TotpChangeRefusal::AlreadyEnabled, $exception->refusal);
        }
        $this->assertNull($pending->find($this->user->getId()));
    }

    /** @param list<string> $secrets The secrets generated, in order */
    private function handler(PendingTotpSecrets $pending, array $secrets): StartTotpSetupHandler
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturn($this->user);
        $verifier = $this->createStub(TotpVerifierInterface::class);
        $verifier->method('generateSecret')->willReturnOnConsecutiveCalls(...$secrets);

        return new StartTotpSetupHandler($users, $verifier, $pending);
    }
}
