<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\Service;

use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Application\Exception\PasswordPolicyException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Application\Service\ChainConnectionCloser;
use App\Auth\Application\Service\CredentialChangeRevoker;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Domain\Event\PasswordChanged;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class PasswordChangerTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];
    private bool $inTransaction = false;
    private ?AccessToken $storedToken = null;
    /** Whether storage holds the kept session as signed in under the locked epoch. */
    private bool $keptSessionCurrent = true;

    public function testSetsThePasswordAndEndsEverySessionInOneTransaction(): void
    {
        $user = User::register(new Email('owner@baander.app'), 'old-hash', 'Owner');

        $changer = $this->changer();
        $sessions = $changer->change($user, $changer->hash('new-password'));

        self::assertSame('hashed:new-password', $user->getPassword());
        self::assertSame(
            ['revoked-session-token'],
            $sessions->accessTokenIds,
            'The caller closes the revoked sessions after the commit.',
        );
        self::assertSame([
            'hash',
            // The reset token row before the user's row, in the order a reset redemption locks them.
            'revoke reset token of ' . $user->getId()->toString(),
            'lock ' . $user->getId()->toString(),
            'save:hashed:new-password',
            'event user.password_changed for ' . $user->getId()->toString(),
            'advance epoch of ' . $user->getId()->toString(),
            'revoke refresh for ' . $user->getId()->toString() . ' keeping none',
            'revoke access for ' . $user->getId()->toString() . ' keeping none',
        ], $this->calls);
    }

    public function testKeepsTheRequestingSession(): void
    {
        $user = User::register(new Email('owner@baander.app'), 'old-hash', 'Owner');
        $this->storedToken = AccessToken::issue(Client::create('SPA', ['https://baander.app']), SignInGrant::Password, $user);

        $changer = $this->changer();
        $sessions = $changer->change($user, $changer->hash('new-password'), $this->storedToken->getTokenId()->toString());

        self::assertTrue($user->getId()->equals($sessions->userId));
        $kept = ' keeping ' . $this->storedToken->getTokenId()->toString();
        self::assertContains('revoke access for ' . $user->getId()->toString() . $kept, $this->calls);
        self::assertContains('revoke refresh for ' . $user->getId()->toString() . $kept, $this->calls);
        // The kept session moves to the new epoch, so it still authenticates and refreshes.
        self::assertContains('restamp access ' . $this->storedToken->getTokenId()->toString() . ' to 8', $this->calls);
        self::assertContains('restamp refresh ' . $this->storedToken->getTokenId()->toString() . ' to 8', $this->calls);
    }

    public function testAChangeFromASessionAnotherChangeEndedLeavesThePasswordAlone(): void
    {
        $user = User::register(new Email('owner@baander.app'), 'old-hash', 'Owner');
        $this->storedToken = AccessToken::issue(Client::create('SPA', ['https://baander.app']), SignInGrant::Password, $user);
        $this->keptSessionCurrent = false;

        $changer = $this->changer();
        $password = $changer->hash('new-password');
        $this->expectException(CredentialsChangedException::class);

        try {
            $sessions = $changer->change($user, $password, $this->storedToken->getTokenId()->toString());
        } finally {
            self::assertFalse(isset($sessions), 'No sessions were revoked.');
            self::assertSame('old-hash', $user->getPassword());
            self::assertNotContains('save:hashed:new-password', $this->calls);
        }
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
            $this->changer()->hash($password);
            self::fail('The policy must reject the password.');
        } catch (PasswordPolicyException) {
        }

        self::assertSame([], $this->calls);
        self::assertSame('old-hash', $user->getPassword());
    }

    public function testAcceptsTheBoundaryLengthsCountedInCharacters(): void
    {
        $user = User::register(new Email('owner@baander.app'), 'old-hash', 'Owner');

        $changer = $this->changer();
        $shortestPassword = str_repeat('æ', 8);
        $first = $changer->change($user, $changer->hash($shortestPassword));
        $longestPassword = str_repeat('æ', 255);
        $second = $changer->change($user, $changer->hash($longestPassword));

        self::assertSame('hashed:' . str_repeat('æ', 255), $user->getPassword());
        self::assertTrue($first->userId->equals($second->userId));
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
        $users->method('lockCredentialEpoch')->willReturnCallback(function (Uuid $userId): int {
            $this->assertTransaction();
            $this->calls[] = 'lock ' . $userId->toString();

            return 7;
        });
        $users->method('advanceCredentialEpoch')->willReturnCallback(function (Uuid $userId): int {
            $this->assertTransaction();
            $this->calls[] = 'advance epoch of ' . $userId->toString();

            return 8;
        });
        $users->method('save')->willReturnCallback(function (User $user): void {
            $this->assertTransaction();
            $this->calls[] = 'save:' . $user->getPassword();
        });
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('findByTokenId')->willReturnCallback(
            fn (TokenId $id): ?AccessToken => $this->storedToken?->getTokenId()->equals($id) === true ? $this->storedToken : null,
        );
        $accessTokens->method('isSessionCurrent')->willReturnCallback(fn (): bool => $this->keptSessionCurrent);
        $accessTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): array {
            $this->assertTransaction();
            $this->calls[] = 'revoke access for ' . $userId->toString() . ' keeping ' . ($keep?->getTokenId()->toString() ?? 'none');

            return ['revoked-session-token'];
        });
        $accessTokens->method('restampCredentialEpoch')->willReturnCallback(function (AccessToken $keep, int $epoch): void {
            $this->assertTransaction();
            $this->calls[] = 'restamp access ' . $keep->getTokenId()->toString() . ' to ' . $epoch;
        });
        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('restampCredentialEpoch')->willReturnCallback(function (AccessToken $keep, int $epoch): void {
            $this->assertTransaction();
            $this->calls[] = 'restamp refresh ' . $keep->getTokenId()->toString() . ' to ' . $epoch;
        });
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

        $resetTokens = $this->createStub(PasswordResetTokenRepositoryInterface::class);
        $resetTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId): void {
            $this->assertTransaction();
            $this->calls[] = 'revoke reset token of ' . $userId->toString();
        });

        $revoker = new CredentialChangeRevoker(
            $users,
            $accessTokens,
            $refreshTokens,
            new ChainConnectionCloser($this->createStub(LiveConnectionsPortInterface::class), new NullLogger()),
        );

        return new PasswordChanger($hasher, $users, $revoker, $transaction, $events, $resetTokens);
    }

    private function assertTransaction(): void
    {
        self::assertTrue($this->inTransaction, 'Writes happen inside the transaction.');
    }
}
