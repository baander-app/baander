<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\DisableUserCommand;
use App\Auth\Application\Command\User\EnableUserCommand;
use App\Auth\Application\CommandHandler\User\DisableUserHandler;
use App\Auth\Application\CommandHandler\User\EnableUserHandler;
use App\Auth\Application\Exception\LiveConnectionsNotClosedException;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\LiveConnectionsPortInterface;
use App\Shared\Application\Port\ServerControlException;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Disabling ends every session in one transaction, then closes the user's live
 * connections; disable and enable are idempotent.
 */
final class DisableEnableUserHandlersTest extends TestCase
{
    private User $user;
    /** @var list<string> what happened, in order; "+" marks a step inside the transaction */
    private array $log = [];
    private bool $inTransaction = false;
    private ?\Throwable $refreshRevocationFailure = null;
    private ?\Throwable $liveConnectionsFailure = null;
    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->user = User::register(new Email('member@baander.app'), 'hashed-password', 'Member');
    }

    public function testDisablingRevokesEveryAccessAndRefreshTokenInTheSameTransaction(): void
    {
        $this->disableHandler()(new DisableUserCommand('member@baander.app'));

        self::assertTrue($this->user->isDisabled());
        self::assertSame(
            ['begin', '+save', '+revoke access tokens', '+revoke refresh tokens', 'commit', 'close live connections'],
            $this->log,
            'Live connections close after the commit, so a reconnect cannot pass on a token the commit has not yet revoked.',
        );
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
        self::assertSame(['close live connections'], $this->log, 'Repeating the disable retries closing live connections.');
    }

    public function testWithoutAWebServerInThisContainerTheDisableSucceedsAndSaysConnectionsWereNotClosed(): void
    {
        $this->liveConnectionsFailure = new ServerNotRunningException();

        $this->disableHandler()(new DisableUserCommand('member@baander.app'));

        self::assertTrue($this->user->isDisabled());
        self::assertSame(['begin', '+save', '+revoke access tokens', '+revoke refresh tokens', 'commit'], $this->log);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('web container', $this->warnings[0]);
    }

    public function testAWebServerThatCannotCloseTheConnectionsFailsTheDisableAfterItCommitted(): void
    {
        $this->liveConnectionsFailure = new ServerControlException('the web server did not answer within 5 seconds');

        try {
            $this->disableHandler()(new DisableUserCommand('member@baander.app'));
            self::fail('A failed close must be reported.');
        } catch (LiveConnectionsNotClosedException $exception) {
            self::assertSame(
                'User "member@baander.app" is disabled and its tokens are revoked, but its open WebSocket connections '
                . 'were not closed: the web server did not answer within 5 seconds. Disable the user again to retry.',
                $exception->getMessage(),
            );
        }

        self::assertTrue($this->user->isDisabled());
        self::assertSame(['begin', '+save', '+revoke access tokens', '+revoke refresh tokens', 'commit'], $this->log);
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

        $liveConnections = $this->createStub(LiveConnectionsPortInterface::class);
        $liveConnections->method('closeForUser')->willReturnCallback(function (Uuid $userId): int {
            self::assertTrue($this->user->getId()->equals($userId));
            if ($this->liveConnectionsFailure !== null) {
                throw $this->liveConnectionsFailure;
            }
            $this->record('close live connections');

            return 2;
        });

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context): void {
            $this->warnings[] = (string) $message;
        });

        $users = $this->userRepository();

        return new DisableUserHandler(
            new UserLookup($users),
            $users,
            $accessTokens,
            $refreshTokens,
            $this->transaction(),
            $liveConnections,
            $logger,
        );
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
