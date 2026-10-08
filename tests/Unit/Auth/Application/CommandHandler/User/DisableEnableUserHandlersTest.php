<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Command\User\EnableUserCommand;
use App\Auth\Application\CommandHandler\User\DisableUserHandler;
use App\Auth\Application\CommandHandler\User\EnableUserHandler;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

/** Disabling ends every session in one transaction; disable and enable are idempotent. */
final class DisableEnableUserHandlersTest extends TestCase
{
    private User $user;
    /** @var list<string> what happened, in order; "+" marks a step inside the transaction */
    private array $log = [];
    private bool $inTransaction = false;
    private ?\Throwable $refreshRevocationFailure = null;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('member@baander.app'), 'hashed-password', 'Member');
    }

    public function testDisablingRevokesEveryAccessAndRefreshTokenInTheSameTransaction(): void
    {
        $this->disableHandler()(new DisableUserCommand('member@baander.app'));

        self::assertTrue($this->user->isDisabled());
        self::assertSame(['begin', '+save', '+revoke access tokens', '+revoke refresh tokens', 'commit'], $this->log);
    }

    public function testDisablingByUuidFindsTheSameUser(): void
    {
        $this->disableHandler()(new DisableUserCommand($this->user->getId()->toString()));

        self::assertTrue($this->user->isDisabled());
    }

    public function testDisablingADisabledUserSucceedsWithoutChange(): void
    {
        $this->user->disable();

        $returned = $this->disableHandler()(new DisableUserCommand('member@baander.app'));

        self::assertSame($this->user, $returned, 'The admin API renders the returned user.');
        self::assertTrue($this->user->isDisabled());
        self::assertSame([], $this->log);
    }

    public function testAFailureWhileRevokingAbortsTheTransactionThatSavesTheDisable(): void
    {
        $this->refreshRevocationFailure = new \RuntimeException('database unavailable');

        $failure = null;
        try {
            $this->disableHandler()(new DisableUserCommand('member@baander.app'));
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertSame($this->refreshRevocationFailure, $failure, 'A failed revocation must fail the disable.');
        // The save ran inside the transaction that the failure rolled back.
        self::assertSame(['begin', '+save', '+revoke access tokens', 'rollback'], $this->log);
    }

    public function testEnablingADisabledUserSavesIt(): void
    {
        $this->user->disable();

        $returned = $this->enableHandler()(new EnableUserCommand('member@baander.app'));

        self::assertSame($this->user, $returned, 'The admin API renders the returned user.');
        self::assertFalse($this->user->isDisabled());
        self::assertSame(['save'], $this->log);
    }

    public function testEnablingAnEnabledUserSucceedsWithoutChange(): void
    {
        $this->enableHandler()(new EnableUserCommand('member@baander.app'));

        self::assertFalse($this->user->isDisabled());
        self::assertSame([], $this->log);
    }

    public function testAnUnknownUserIsNotFoundOnBothPaths(): void
    {
        foreach ([
            fn () => $this->disableHandler()(new DisableUserCommand('nobody@baander.app')),
            fn () => $this->disableHandler()(new DisableUserCommand(Uuid::generate()->toString())),
            fn () => $this->enableHandler()(new EnableUserCommand('nobody@baander.app')),
            fn () => $this->enableHandler()(new EnableUserCommand('not-a-uuid')),
        ] as $action) {
            try {
                $action();
                self::fail('An unknown user must not be found.');
            } catch (NotFoundException) {
            }
        }

        self::assertSame([], $this->log);
    }

    private function disableHandler(): DisableUserHandler
    {
        $accessTokens = $this->createStub(AccessTokenRepositoryInterface::class);
        $accessTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): void {
            self::assertTrue($this->user->getId()->equals($userId));
            self::assertNull($keep);
            $this->record('revoke access tokens');
        });

        $refreshTokens = $this->createStub(RefreshTokenRepositoryInterface::class);
        $refreshTokens->method('revokeForUser')->willReturnCallback(function (Uuid $userId, ?AccessToken $keep): void {
            self::assertTrue($this->user->getId()->equals($userId));
            self::assertNull($keep);
            if ($this->refreshRevocationFailure !== null) {
                throw $this->refreshRevocationFailure;
            }
            $this->record('revoke refresh tokens');
        });

        $users = $this->userRepository();

        return new DisableUserHandler(new UserLookup($users), $users, $accessTokens, $refreshTokens, $this->transaction());
    }

    private function enableHandler(): EnableUserHandler
    {
        $users = $this->userRepository();

        return new EnableUserHandler(new UserLookup($users), $users);
    }

    private function userRepository(): UserRepositoryInterface
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnCallback(
            fn (Uuid $id): ?User => $id->equals($this->user->getId()) ? $this->user : null,
        );
        $users->method('findByEmail')->willReturnCallback(
            fn (Email $email): ?User => $email->toString() === $this->user->getEmail() ? $this->user : null,
        );
        $users->method('save')->willReturnCallback(function (User $user): void {
            self::assertSame($this->user, $user);
            $this->record('save');
        });

        return $users;
    }

    private function transaction(): TransactionPortInterface
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->log[] = 'begin';
            $this->inTransaction = true;
            try {
                $result = $operation();
            } catch (\Throwable $exception) {
                $this->log[] = 'rollback';
                throw $exception;
            } finally {
                $this->inTransaction = false;
            }
            $this->log[] = 'commit';

            return $result;
        });

        return $transaction;
    }

    private function record(string $step): void
    {
        $this->log[] = ($this->inTransaction ? '+' : '') . $step;
    }
}
