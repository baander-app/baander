<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ResetUserTotpCommand;
use App\Auth\Application\CommandHandler\User\ResetUserTotpHandler;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\AccessTokenRepositoryInterface;
use App\Auth\Domain\Repository\OAuth\RefreshTokenRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

/** An operator's TOTP reset turns TOTP off and ends every session in one transaction. */
final class ResetUserTotpHandlerTest extends TestCase
{
    private User $user;
    /** @var list<string> what happened, in order; "+" marks a step inside the transaction */
    private array $log = [];
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $this->user = User::register(new Email('member@baander.app'), 'hashed-password', 'Member');
        $this->user->enableTotp('ciphertext the configured key cannot open');
    }

    public function testTheResetTurnsTotpOffAndRevokesEveryTokenInOneTransaction(): void
    {
        $returned = $this->handler()(new ResetUserTotpCommand($this->user->getPublicId()->toString()));

        self::assertSame($this->user, $returned, 'The admin API renders the returned user.');
        self::assertFalse($this->user->hasTotp());
        self::assertNull($this->user->getTotpSecretCiphertext());
        self::assertSame(['begin', '+save', '+clear TOTP step', '+revoke access tokens', '+revoke refresh tokens', 'commit'], $this->log);
    }

    public function testAUserWithoutTotpIsAConflictAndNothingChanges(): void
    {
        $this->user->disableTotp();

        try {
            $this->handler()(new ResetUserTotpCommand('member@baander.app'));
            self::fail('A user without TOTP has nothing to reset.');
        } catch (ConflictException $conflict) {
            self::assertSame(['reason' => 'totp_not_enabled'], $conflict->details);
        }

        self::assertSame([], $this->log);
    }

    public function testAnUnknownUserIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->handler()(new ResetUserTotpCommand('nobody@baander.app'));
    }

    private function handler(): ResetUserTotpHandler
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByPublicId')->willReturnCallback(fn (PublicId $id): ?User => $id->equals($this->user->getPublicId()) ? $this->user : null);
        $users->method('findByEmail')->willReturnCallback(fn (Email $email): ?User => $email->toString() === $this->user->getEmail() ? $this->user : null);
        $users->method('save')->willReturnCallback(function (User $user): void {
            self::assertSame($this->user, $user);
            $this->record('save');
        });
        $users->method('clearTotpStep')->willReturnCallback(function (Uuid $userId): void {
            self::assertTrue($this->user->getId()->equals($userId));
            $this->record('clear TOTP step');
        });
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
            $this->record('revoke refresh tokens');
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->log[] = 'begin';
            $this->inTransaction = true;
            try {
                return $operation();
            } finally {
                $this->inTransaction = false;
                $this->log[] = 'commit';
            }
        });

        return new ResetUserTotpHandler(new UserLookup($users), $users, $accessTokens, $refreshTokens, $transaction);
    }

    private function record(string $step): void
    {
        $this->log[] = ($this->inTransaction ? '+' : '') . $step;
    }
}
