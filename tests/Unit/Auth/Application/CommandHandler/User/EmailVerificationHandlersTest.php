<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\ChangeEmailCommand;
use App\Auth\Application\Command\User\ResendEmailVerificationCommand;
use App\Auth\Application\CommandHandler\User\ChangeEmailHandler;
use App\Auth\Application\CommandHandler\User\ResendEmailVerificationHandler;
use App\Auth\Application\DTO\IssuedEmailVerificationToken;
use App\Auth\Application\Exception\EmailAddressInUseException;
use App\Auth\Application\Exception\UserNotFoundException;
use App\Auth\Application\Port\EmailVerificationDeliveryInterface;
use App\Auth\Application\Port\EmailVerificationResendThrottleInterface;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Service\EmailVerificationIssuer;
use App\Auth\Application\Service\UserLookup;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;

/** Email changes and resend requests issue a token for the current address and send it after the commit. */
final class EmailVerificationHandlersTest extends TestCase
{
    private bool $active = false;
    /** @var array<string, User> by UUID */
    private array $users = [];
    /** @var list<string> addresses taken by other accounts */
    private array $taken = [];
    /** @var list<array{string, bool}> saved email, inside the transaction */
    private array $saves = [];
    /** @var list<array{string, string}> email, token */
    private array $issued = [];
    /** @var list<array{string, string, bool}> recipient, token, inside the transaction */
    private array $delivered = [];
    private bool $throttleAllows = true;

    public function testAnEmailChangeSendsANewLinkToTheNewAddressAfterTheCommit(): void
    {
        $user = $this->user('alice@baander.app');

        $changed = ($this->changeHandler())(new ChangeEmailCommand($user->getId()->toString(), 'Alice.New@baander.app'));

        $this->assertSame($user, $changed);
        $this->assertSame('alice.new@baander.app', $user->getEmail());
        $this->assertFalse($user->isEmailVerified());
        $this->assertSame([['alice.new@baander.app', true]], $this->saves);
        $this->assertCount(1, $this->issued);
        $this->assertSame('alice.new@baander.app', $this->issued[0][0]);
        $this->assertSame([['alice.new@baander.app', $this->issued[0][1], false]], $this->delivered);
    }

    public function testAVerifiedUsersEmailChangeStartsUnverified(): void
    {
        $user = User::createByOperator(new Email('alice@baander.app'), 'hashed', 'Alice', ['ROLE_USER']);
        $this->users[$user->getId()->toString()] = $user;

        ($this->changeHandler())(new ChangeEmailCommand('alice@baander.app', 'alice.new@baander.app'));

        $this->assertFalse($user->isEmailVerified());
        $this->assertCount(1, $this->delivered);
    }

    public function testAnUnchangedAddressChangesAndSendsNothing(): void
    {
        $user = $this->user('alice@baander.app');

        ($this->changeHandler())(new ChangeEmailCommand($user->getId()->toString(), 'ALICE@baander.app'));

        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->delivered);
    }

    public function testAnAddressInUseIsRejected(): void
    {
        $user = $this->user('alice@baander.app');
        $this->taken[] = 'bob@baander.app';

        $this->expectException(EmailAddressInUseException::class);

        try {
            ($this->changeHandler())(new ChangeEmailCommand($user->getId()->toString(), 'bob@baander.app'));
        } finally {
            $this->assertSame('alice@baander.app', $user->getEmail());
            $this->assertSame([], $this->delivered);
        }
    }

    public function testAnUnknownUserIsReported(): void
    {
        $this->expectException(UserNotFoundException::class);

        ($this->changeHandler())(new ChangeEmailCommand('nobody@baander.app', 'new@baander.app'));
    }

    public function testResendIssuesAndDeliversForAnUnverifiedUser(): void
    {
        $user = $this->user('alice@baander.app');

        ($this->resendHandler())(new ResendEmailVerificationCommand($user->getId()->toString()));

        $this->assertCount(1, $this->issued);
        $this->assertSame([['alice@baander.app', $this->issued[0][1], false]], $this->delivered);
    }

    public function testResendSendsNothingOverTheLimitOrForAVerifiedAddress(): void
    {
        $unverified = $this->user('alice@baander.app');
        $verified = User::createByOperator(new Email('bob@baander.app'), 'hashed', 'Bob', ['ROLE_USER']);
        $this->users[$verified->getId()->toString()] = $verified;

        ($this->resendHandler())(new ResendEmailVerificationCommand($verified->getId()->toString()));
        $this->throttleAllows = false;
        ($this->resendHandler())(new ResendEmailVerificationCommand($unverified->getId()->toString()));
        ($this->resendHandler())(new ResendEmailVerificationCommand(Uuid::generate()->toString()));

        $this->assertSame([], $this->issued);
        $this->assertSame([], $this->delivered);
    }

    public function testTheIssuerSkipsDisabledAccountsAndRejectsAShortLifetime(): void
    {
        $user = $this->user('alice@baander.app');
        $user->disable();

        $this->assertNull($this->issuer()->issue($user));

        $this->expectException(\InvalidArgumentException::class);
        new EmailVerificationIssuer($this->createStub(EmailVerificationTokenRepositoryInterface::class), $this->createStub(EmailVerificationDeliveryInterface::class), 59);
    }

    public function testAnIssuedTokenCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);

        serialize(new IssuedEmailVerificationToken('raw', new \DateTimeImmutable()));
    }

    private function user(string $email): User
    {
        $user = User::register(new Email($email), 'hashed', 'Alice');
        $this->users[$user->getId()->toString()] = $user;

        return $user;
    }

    private function changeHandler(): ChangeEmailHandler
    {
        return new ChangeEmailHandler(new UserLookup($this->userRepository()), $this->userRepository(), $this->issuer(), $this->transaction());
    }

    private function resendHandler(): ResendEmailVerificationHandler
    {
        $throttle = $this->createStub(EmailVerificationResendThrottleInterface::class);
        $throttle->method('tryAcquire')->willReturnCallback(fn (): bool => $this->throttleAllows);

        return new ResendEmailVerificationHandler($this->userRepository(), $throttle, $this->issuer(), $this->transaction());
    }

    private function issuer(): EmailVerificationIssuer
    {
        $tokens = $this->createStub(EmailVerificationTokenRepositoryInterface::class);
        $tokens->method('issue')->willReturnCallback(function (Uuid $userId, Email $email, string $token): void {
            $this->assertTrue($this->active, 'The token is stored inside the transaction.');
            $this->issued[] = [$email->toString(), $token];
        });
        $delivery = $this->createStub(EmailVerificationDeliveryInterface::class);
        $delivery->method('deliver')->willReturnCallback(function (User $user, string $token): void {
            $this->delivered[] = [$user->getEmail(), $token, $this->active];
        });

        return new EmailVerificationIssuer($tokens, $delivery, 86400);
    }

    private function userRepository(): UserRepositoryInterface
    {
        $repository = $this->createStub(UserRepositoryInterface::class);
        $repository->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?User => $this->users[$id->toString()] ?? null);
        $repository->method('findByEmail')->willReturnCallback(function (Email $email): ?User {
            foreach ($this->users as $user) {
                if ($user->getEmail() === $email->toString()) {
                    return $user;
                }
            }

            return null;
        });
        $repository->method('existsWithEmail')->willReturnCallback(fn (Email $email): bool => in_array($email->toString(), $this->taken, true));
        $repository->method('save')->willReturnCallback(function (User $user): void {
            $this->saves[] = [$user->getEmail(), $this->active];
        });

        return $repository;
    }

    private function transaction(): TransactionPortInterface
    {
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->active = true;
            try {
                return $operation();
            } finally {
                $this->active = false;
            }
        });

        return $transaction;
    }
}
