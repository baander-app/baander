<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler\User;

use App\Auth\Application\Command\User\VerifyEmailCommand;
use App\Auth\Application\CommandHandler\User\VerifyEmailHandler;
use App\Auth\Application\DTO\RedeemedEmailVerification;
use App\Auth\Application\Exception\EmailVerificationException;
use App\Auth\Application\Port\EmailChangeNoticeInterface;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Domain\Event\EmailVerified;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class VerifyEmailHandlerTest extends TestCase
{
    private bool $active = false;
    private ?RedeemedEmailVerification $redeemed = null;
    private ?User $user = null;
    /** @var list<string> */
    private array $redeemedTokens = [];
    /** @var list<bool> whether each save ran inside the transaction */
    private array $saves = [];
    /** @var list<object> */
    private array $events = [];
    private ?\Throwable $eventFailure = null;
    /** @var list<string> addresses other accounts use */
    private array $taken = [];
    /** @var list<array{string, string, bool}> name, previous address, inside the transaction */
    private array $notices = [];

    public function testVerifiesTheAddressTheTokenWasIssuedFor(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->redeemed = new RedeemedEmailVerification($this->user->getId(), new Email('alice@baander.app'));

        ($this->handler())(new VerifyEmailCommand(' raw-token '));

        $this->assertSame(['raw-token'], $this->redeemedTokens);
        $this->assertTrue($this->user->isEmailVerified());
        $this->assertSame([true], $this->saves);
        $this->assertCount(1, $this->events);
        $this->assertInstanceOf(EmailVerified::class, $this->events[0]);
    }

    public function testAnAlreadyVerifiedAddressSucceedsWithoutASecondEvent(): void
    {
        $this->user = User::createByOperator(new Email('alice@baander.app'), 'hashed-pw', 'Alice', ['ROLE_USER']);
        $this->redeemed = new RedeemedEmailVerification($this->user->getId(), new Email('alice@baander.app'));

        ($this->handler())(new VerifyEmailCommand('raw-token'));

        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->events);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableTokens(): iterable
    {
        yield 'empty token' => ['empty'];
        yield 'unknown or expired token' => ['unknown'];
        yield 'user gone' => ['no-user'];
        yield 'address changed since' => ['other-address'];
        yield 'disabled account' => ['disabled'];
    }

    #[DataProvider('unusableTokens')]
    public function testAnUnusableTokenGetsOneAnswerAndVerifiesNothing(string $case): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->redeemed = new RedeemedEmailVerification($this->user->getId(), new Email('alice@baander.app'));
        match ($case) {
            'unknown' => $this->redeemed = null,
            'no-user' => $this->user = null,
            'other-address' => $this->redeemed = new RedeemedEmailVerification($this->user->getId(), new Email('old-alice@baander.app')),
            'disabled' => $this->user->disable(),
            default => null,
        };

        try {
            ($this->handler())(new VerifyEmailCommand($case === 'empty' ? '  ' : 'raw-token'));
            $this->fail('An unusable token must be rejected.');
        } catch (EmailVerificationException $exception) {
            $this->assertSame('Invalid or expired verification token.', $exception->getMessage());
        }

        $this->assertSame([], $this->saves);
        $this->assertSame([], $this->events);
        $this->assertSame($case === 'empty' ? [] : ['raw-token'], $this->redeemedTokens);
    }

    public function testAPendingAddressBecomesTheVerifiedAddressAndTheOldOneIsTold(): void
    {
        $this->user = User::createByOperator(new Email('alice@baander.app'), 'hashed-pw', 'Alice', ['ROLE_USER']);
        $this->user->requestEmailChange(new Email('alice.new@baander.app'));
        $this->redeemed = new RedeemedEmailVerification($this->user->getId(), new Email('alice.new@baander.app'));

        ($this->handler())(new VerifyEmailCommand('raw-token'));

        $this->assertSame('alice.new@baander.app', $this->user->getEmail());
        $this->assertTrue($this->user->isEmailVerified());
        $this->assertNull($this->user->getPendingEmail());
        $this->assertSame([true], $this->saves);
        $this->assertCount(1, $this->events);
        $this->assertSame([['Alice', 'alice@baander.app', false]], $this->notices, 'The old address hears of the change after the commit.');
    }

    public function testAPendingAddressAnotherAccountTookFailsAndKeepsTheAddress(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->user->requestEmailChange(new Email('bob@baander.app'));
        $this->redeemed = new RedeemedEmailVerification($this->user->getId(), new Email('bob@baander.app'));
        $this->taken[] = 'bob@baander.app';

        try {
            ($this->handler())(new VerifyEmailCommand('raw-token'));
            $this->fail('A taken address must not be confirmed.');
        } catch (EmailVerificationException $exception) {
            $this->assertSame('Invalid or expired verification token.', $exception->getMessage());
        }

        $this->assertSame('alice@baander.app', $this->user->getEmail());
        $this->assertNull($this->user->getPendingEmail(), 'The change is dropped.');
        $this->assertSame([true], $this->saves);
        $this->assertSame([], $this->events);
        $this->assertSame([], $this->notices);
    }

    public function testATokenForAnAddressNoLongerPendingVerifiesNothing(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->user->requestEmailChange(new Email('alice.second@baander.app'));
        $this->redeemed = new RedeemedEmailVerification($this->user->getId(), new Email('alice.first@baander.app'));

        $this->expectException(EmailVerificationException::class);

        try {
            ($this->handler())(new VerifyEmailCommand('raw-token'));
        } finally {
            $this->assertSame('alice@baander.app', $this->user->getEmail());
            $this->assertSame('alice.second@baander.app', $this->user->getPendingEmail());
            $this->assertSame([], $this->saves);
        }
    }

    public function testAnEventFailureEscapesTheTransaction(): void
    {
        $this->user = User::register(new Email('alice@baander.app'), 'hashed-pw', 'Alice');
        $this->redeemed = new RedeemedEmailVerification($this->user->getId(), new Email('alice@baander.app'));
        $this->eventFailure = new \RuntimeException('Outbox insertion failed.');

        $this->expectExceptionObject($this->eventFailure);

        ($this->handler())(new VerifyEmailCommand('raw-token'));
    }

    private function handler(): VerifyEmailHandler
    {
        $tokens = $this->createStub(EmailVerificationTokenRepositoryInterface::class);
        $tokens->method('redeem')->willReturnCallback(function (string $token): ?RedeemedEmailVerification {
            $this->assertTrue($this->active, 'Redemption is part of the verification transaction.');
            $this->redeemedTokens[] = $token;

            return $this->redeemed;
        });
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByUuid')->willReturnCallback(fn (): ?User => $this->user);
        $users->method('save')->willReturnCallback(function (): void {
            $this->saves[] = $this->active;
        });
        $users->method('existsWithEmail')->willReturnCallback(fn (Email $email): bool => in_array($email->toString(), $this->taken, true));
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->assertTrue($this->active);
            if ($this->eventFailure !== null) {
                throw $this->eventFailure;
            }
            $this->events[] = $event;

            return $event;
        });
        $transaction = $this->createStub(TransactionPortInterface::class);
        $transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->active = true;
            try {
                return $operation();
            } finally {
                $this->active = false;
            }
        });

        $notice = $this->createStub(EmailChangeNoticeInterface::class);
        $notice->method('deliver')->willReturnCallback(function (User $user, Email $previous): void {
            $this->notices[] = [$user->getName(), $previous->toString(), $this->active];
        });

        return new VerifyEmailHandler($tokens, $users, $dispatcher, $transaction, $notice);
    }
}
