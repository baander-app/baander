<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangePasswordCommand;
use App\Auth\Application\Command\User\ResetPasswordCommand;
use App\Auth\Application\Command\User\SetUserPasswordCommand;
use App\Auth\Application\CommandHandler\User\ChangePasswordHandler;
use App\Auth\Application\CommandHandler\User\ResetPasswordHandler;
use App\Auth\Application\CommandHandler\User\SetUserPasswordHandler;
use App\Auth\Application\Exception\CurrentPasswordMismatchException;
use App\Auth\Application\Exception\PasswordResetException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\PasswordResetTokenRepositoryInterface;
use App\Auth\Application\Service\PasswordChanger;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\TokenId;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/** The three password paths share PasswordChanger; these tests pin who may use it and which session survives. */
final class PasswordHandlersTest extends TestCase
{
    private User $user;
    /** @var array<string, User> */
    private array $users = [];
    /** @var list<string> */
    private array $keptSessions = [];
    private ?AccessToken $storedToken = null;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('owner@baander.app'), 'hashed:current-password', 'Owner');
        $this->users[$this->user->getId()->toString()] = $this->user;
    }

    public function testResetRedeemsTheTokenAndSignsOutEverySession(): void
    {
        $handler = new ResetPasswordHandler($this->resetTokens($this->user->getId()), $this->userRepository(), $this->changer());

        $handler(new ResetPasswordCommand('raw-token', 'new-password'));

        self::assertSame('hashed:new-password', $this->user->getPassword());
        self::assertSame(['none'], $this->keptSessions);
    }

    public function testResetRejectsATokenThatRedeemsToNoUser(): void
    {
        $handler = new ResetPasswordHandler($this->resetTokens(null), $this->userRepository(), $this->changer());

        $this->expectExceptionObject(PasswordResetException::invalidToken());
        $handler(new ResetPasswordCommand('raw-token', 'new-password'));
    }

    public function testResetRejectsATokenWhoseUserNoLongerExists(): void
    {
        $handler = new ResetPasswordHandler($this->resetTokens(Uuid::generate()), $this->userRepository(), $this->changer());

        $this->expectExceptionObject(PasswordResetException::invalidToken());
        $handler(new ResetPasswordCommand('raw-token', 'new-password'));
    }

    public function testResetRejectsADisabledAccountWithTheSameError(): void
    {
        $this->user->disable();
        $handler = new ResetPasswordHandler($this->resetTokens($this->user->getId()), $this->userRepository(), $this->changer());

        try {
            $handler(new ResetPasswordCommand('raw-token', 'new-password'));
            self::fail('A disabled account cannot be reset.');
        } catch (PasswordResetException $e) {
            self::assertSame(PasswordResetException::invalidToken()->getMessage(), $e->getMessage());
        }
        self::assertSame('hashed:current-password', $this->user->getPassword());
    }

    public function testOperatorResetFindsTheUserByEmailOrUuidAndSignsOutEverySession(): void
    {
        $handler = new SetUserPasswordHandler(new UserLookup($this->userRepository()), $this->changer());

        $handler(new SetUserPasswordCommand('Owner@Baander.app', 'by-email-password'));
        self::assertSame('hashed:by-email-password', $this->user->getPassword());
        $handler(new SetUserPasswordCommand($this->user->getId()->toString(), 'by-uuid-password'));
        self::assertSame('hashed:by-uuid-password', $this->user->getPassword());

        self::assertSame(['none', 'none'], $this->keptSessions);
    }

    public function testOperatorResetReportsAnUnknownUser(): void
    {
        $handler = new SetUserPasswordHandler(new UserLookup($this->userRepository()), $this->changer());

        foreach (['nobody@baander.app', Uuid::generate()->toString(), 'not-a-uuid'] as $identifier) {
            try {
                $handler(new SetUserPasswordCommand($identifier, 'new-password'));
                self::fail('An unknown user must be reported.');
            } catch (UserNotFoundException $e) {
                self::assertStringContainsString($identifier, $e->getMessage());
            }
        }
    }

    public function testChangeKeepsTheRequestingSession(): void
    {
        $this->storedToken = AccessToken::issue(Client::create('SPA', ['https://baander.app']), $this->user);

        $this->changeHandler()(new ChangePasswordCommand(
            $this->user->getId()->toString(),
            'current-password',
            'new-password',
            $this->storedToken->getTokenId()->toString(),
        ));

        self::assertSame('hashed:new-password', $this->user->getPassword());
        self::assertSame([$this->storedToken->getTokenId()->toString()], $this->keptSessions);
    }

    public function testChangeDoesNotKeepAnotherUsersToken(): void
    {
        $other = User::register(new Email('other@baander.app'), 'hashed:x', 'Other');
        $this->storedToken = AccessToken::issue(Client::create('SPA', ['https://baander.app']), $other);

        $this->changeHandler()(new ChangePasswordCommand(
            $this->user->getId()->toString(),
            'current-password',
            'new-password',
            $this->storedToken->getTokenId()->toString(),
        ));

        self::assertSame(['none'], $this->keptSessions);
    }

    public function testChangeRequiresTheCurrentPassword(): void
    {
        $this->expectException(CurrentPasswordMismatchException::class);

        try {
            $this->changeHandler()(new ChangePasswordCommand($this->user->getId()->toString(), 'wrong-password', 'new-password'));
        } finally {
            self::assertSame('hashed:current-password', $this->user->getPassword());
            self::assertSame([], $this->keptSessions);
        }
    }

    private function changeHandler(): ChangePasswordHandler
    {
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('findByTokenId')->willReturnCallback(
            fn (TokenId $id): ?AccessToken => $this->storedToken?->getTokenId()->equals($id) ? $this->storedToken : null,
        );

        return new ChangePasswordHandler($this->userRepository(), $accessTokens, $this->hasher(), $this->changer());
    }

    private function resetTokens(?Uuid $redeemsTo): PasswordResetTokenRepositoryInterface
    {
        $tokens = $this->createMock(PasswordResetTokenRepositoryInterface::class);
        $tokens->expects($this->once())->method('redeem')->with('raw-token')->willReturn($redeemsTo);

        return $tokens;
    }

    private function userRepository(): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?User => $this->users[$id->toString()] ?? null);
        $users->method('findByEmail')->willReturnCallback(
            fn (Email $email): ?User => $email->toString() === $this->user->getEmail() ? $this->user : null,
        );

        return $users;
    }

    private function hasher(): PasswordHasherInterface
    {
        $hasher = $this->createStub(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturnCallback(static fn (string $plain): string => 'hashed:' . $plain);
        $hasher->method('verify')->willReturnCallback(static fn (string $plain, string $hash): bool => 'hashed:' . $plain === $hash);

        return $hasher;
    }

    private function changer(): PasswordChanger
    {
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): void {
            self::assertTrue($this->user->getId()->equals($userId));
            $this->keptSessions[] = $keep?->getTokenId()->toString() ?? 'none';
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return new PasswordChanger(
            $this->hasher(),
            $this->createStub(UserRepositoryInterface::class),
            $accessTokens,
            $this->createStub(RefreshTokenRepositoryInterface::class),
            $transaction,
            $this->createStub(EventDispatcherInterface::class),
        );
    }
}
