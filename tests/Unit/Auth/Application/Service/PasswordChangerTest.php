<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class PasswordChangerTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];
    private bool $inTransaction = false;

    public function testSetsThePasswordAndEndsEverySessionInOneTransaction(): void
    {
        $user = User::register(new Email('owner@baander.app'), 'old-hash', 'Owner');

        $this->changer()->change($user, 'new-password');

        self::assertSame('hashed:new-password', $user->getPassword());
        self::assertSame([
            'hash',
            'save:hashed:new-password',
            'revoke access for ' . $user->getId()->toString() . ' keeping none',
            'revoke refresh for ' . $user->getId()->toString() . ' keeping none',
            'event user.password_changed for ' . $user->getId()->toString(),
        ], $this->calls);
    }

    public function testKeepsTheGivenSession(): void
    {
        $user = User::register(new Email('owner@baander.app'), 'old-hash', 'Owner');
        $keep = AccessToken::issue(Client::create('SPA', ['https://baander.app']), $user);

        $this->changer()->change($user, 'new-password', $keep);

        $kept = ' keeping ' . $keep->getTokenId()->toString();
        self::assertContains('revoke access for ' . $user->getId()->toString() . $kept, $this->calls);
        self::assertContains('revoke refresh for ' . $user->getId()->toString() . $kept, $this->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function passwordsOutsideThePolicy(): iterable
    {
        yield 'seven characters' => ['1234567'];
        yield '256 characters' => [str_repeat('a', 256)];
    }

    #[DataProvider('passwordsOutsideThePolicy')]
    public function testRejectsAPasswordOutsideThePolicyBeforeHashing(string $password): void
    {
        $user = User::register(new Email('owner@baander.app'), 'old-hash', 'Owner');

        try {
            $this->changer()->change($user, $password);
            self::fail('The policy must reject the password.');
        } catch (PasswordPolicyException) {
        }

        self::assertSame([], $this->calls);
        self::assertSame('old-hash', $user->getPassword());
    }

    public function testAcceptsTheBoundaryLengthsCountedInCharacters(): void
    {
        $user = User::register(new Email('owner@baander.app'), 'old-hash', 'Owner');

        $this->changer()->change($user, str_repeat('æ', 8));
        $this->changer()->change($user, str_repeat('æ', 255));

        self::assertSame('hashed:' . str_repeat('æ', 255), $user->getPassword());
    }

    private function changer(): PasswordChanger
    {
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturnCallback(function (string $plain): string {
            self::assertFalse($this->inTransaction, 'Hashing happens before the transaction opens.');
            $this->calls[] = 'hash';

            return 'hashed:' . $plain;
        });
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('save')->willReturnCallback(function (User $user): void {
            $this->assertTransaction();
            $this->calls[] = 'save:' . $user->getPassword();
        });
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): void {
            $this->assertTransaction();
            $this->calls[] = 'revoke access for ' . $userId->toString() . ' keeping ' . ($keep?->getTokenId()->toString() ?? 'none');
        });
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): void {
            $this->assertTransaction();
            $this->calls[] = 'revoke refresh for ' . $userId->toString() . ' keeping ' . ($keep?->getTokenId()->toString() ?? 'none');
        });
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->assertTransaction();
            self::assertInstanceOf(PasswordChanged::class, $event);
            $this->calls[] = 'event ' . $event->eventName() . ' for ' . $event->getUserId()->toString();

            return $event;
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->inTransaction = true;
            try {
                return $operation();
            } finally {
                $this->inTransaction = false;
            }
        });

        return new PasswordChanger($hasher, $users, $accessTokens, $refreshTokens, $transaction, $events);
    }

    private function assertTransaction(): void
    {
        self::assertTrue($this->inTransaction, 'Writes happen inside the transaction.');
    }
}
