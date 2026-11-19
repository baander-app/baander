<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\User\RegisterUserCommand;
use App\Auth\Application\CommandHandler\User\RegisterUserHandler;
use App\Auth\Application\Port\EmailVerificationDeliveryInterface;
use App\Auth\Application\Port\EmailVerificationTokenRepositoryInterface;
use App\Auth\Application\Port\PasswordHasherInterface;
use App\Auth\Application\Port\RegistrationAttemptNoticeInterface;
use App\Auth\Application\Service\EmailVerificationIssuer;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use App\UserPreference\Application\Port\UserSettingsContractInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RegisterUserHandlerTest extends TestCase
{
    private UserRepositoryInterface&Stub $userRepository;
    private PasswordHasherInterface&Stub $passwordHasher;
    private EventDispatcherInterface&Stub $eventDispatcher;
    private MessageBusInterface&Stub $bus;
    private TransactionPortInterface&Stub $transaction;
    private bool $active = false;
    /** @var list<array{string, string, string, \DateTimeImmutable, bool}> userId, email, token, expiry, inside the transaction */
    private array $issued = [];
    /** @var list<array{User, string, bool}> user, token, inside the transaction */
    private array $delivered = [];
    /** @var list<array{string, string, bool}> userId, language, inside the transaction */
    private array $seeded = [];
    /** @var list<User> */
    private array $saved = [];
    /** @var list<User> owners told about a registration attempt */
    private array $noticed = [];
    /** @var list<string> passwords hashed */
    private array $hashed = [];

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->userRepository->method('findByEmail')->willReturn(null);
        $this->userRepository->method('save')->willReturnCallback(function (User $user): void {
            $this->saved[] = $user;
        });
        $this->passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $this->passwordHasher->method('hash')->willReturnCallback(function (string $password): string {
            $this->hashed[] = $password;

            return 'hashed-pw';
        });
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->bus = $this->createStub(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(fn (object $m) => new Envelope($m));
        $this->transaction = $this->createStub(TransactionPortInterface::class);
        $this->transaction->method('run')->willReturnCallback(function (callable $operation): mixed {
            $this->active = true;
            try {
                return $operation();
            } finally {
                $this->active = false;
            }
        });
    }

    public function testRegistersAnUnverifiedUser(): void
    {
        ($this->handler())(new RegisterUserCommand(new Email('test@baander.app'), 'Alice', 'password123'));
        $user = $this->registered();

        $this->assertSame('Alice', $user->getName());
        $this->assertSame('test@baander.app', $user->getEmail());
        $this->assertFalse($user->isEmailVerified());
    }

    /** A taken address costs the same hash, creates nothing and tells the existing owner. */
    public function testATakenAddressNotifiesItsOwnerAndCreatesNothing(): void
    {
        $owner = User::register(new Email('test@baander.app'), 'owner-hash', 'Owner');
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->userRepository->method('findByEmail')->willReturn($owner);
        $this->userRepository->method('save')->willReturnCallback(static fn (): never => self::fail('A taken address must not create an account.'));

        ($this->handler())(new RegisterUserCommand(new Email('test@baander.app'), 'Alice', 'password123'));

        $this->assertSame(['password123'], $this->hashed, 'The password is hashed as for a new account.');
        $this->assertSame([$owner], $this->noticed);
        $this->assertSame([], $this->issued);
        $this->assertSame([], $this->delivered);
    }

    public function testANewAddressNotifiesNobody(): void
    {
        ($this->handler())(new RegisterUserCommand(new Email('test@baander.app'), 'Alice', 'password123'));

        $this->assertSame([], $this->noticed);
    }

    public function testIssuesATokenInTheTransactionAndDeliversItAfterTheCommit(): void
    {
        ($this->handler())(new RegisterUserCommand(new Email('test@baander.app'), 'Alice', 'password123'));
        $user = $this->registered();

        $this->assertCount(1, $this->issued);
        [$userId, $email, $token, $expiresAt, $inside] = $this->issued[0];
        $this->assertSame($user->getId()->toString(), $userId);
        $this->assertSame('test@baander.app', $email);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, 'A 256-bit random token.');
        $this->assertEqualsWithDelta(time() + 86400, $expiresAt->getTimestamp(), 5);
        $this->assertTrue($inside, 'The token is stored with the user.');

        $this->assertCount(1, $this->delivered);
        $this->assertSame($user, $this->delivered[0][0]);
        $this->assertSame($token, $this->delivered[0][1]);
        $this->assertFalse($this->delivered[0][2], 'The link is sent only after the commit.');
    }

    public function testTheBrowserLanguageIsSeededInsideTheRegistrationTransaction(): void
    {
        ($this->handler())(new RegisterUserCommand(new Email('alice@baander.app'), 'Alice', 'password123', 'da'));
        $user = $this->registered();

        $this->assertSame([[$user->getId()->toString(), 'da', true]], $this->seeded);
    }

    public function testWithoutABrowserLanguageNothingIsSeeded(): void
    {
        ($this->handler())(new RegisterUserCommand(new Email('alice@baander.app'), 'Alice', 'password123'));

        $this->assertSame([], $this->seeded);
    }

    public function testEventFailureEscapesTheTransactionAndSendsNothing(): void
    {
        $failure = new \RuntimeException('Outbox insertion failed.');
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willThrowException($failure);

        try {
            ($this->handler())(new RegisterUserCommand(new Email('alice@baander.app'), 'Alice', 'password123'));
            $this->fail('The event failure must escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame([], $this->delivered, 'A rolled-back registration emails no link.');
    }

    private function handler(): RegisterUserHandler
    {
        $tokens = $this->createStub(EmailVerificationTokenRepositoryInterface::class);
        $tokens->method('issue')->willReturnCallback(function (Uuid $userId, Email $email, string $token, \DateTimeImmutable $expiresAt): void {
            $this->issued[] = [$userId->toString(), $email->toString(), $token, $expiresAt, $this->active];
        });
        $delivery = $this->createStub(EmailVerificationDeliveryInterface::class);
        $delivery->method('deliver')->willReturnCallback(function (User $user, Email $address, string $token): void {
            $this->delivered[] = [$user, $token, $this->active];
        });

        $notice = $this->createStub(RegistrationAttemptNoticeInterface::class);
        $notice->method('deliver')->willReturnCallback(function (User $owner): void {
            $this->noticed[] = $owner;
        });

        return new RegisterUserHandler(
            $this->userRepository,
            $this->passwordHasher,
            $this->eventDispatcher,
            $this->bus,
            new EmailVerificationIssuer($tokens, $delivery, 86400),
            $this->transaction,
            $this->settings(),
            $notice,
        );
    }

    private function registered(): User
    {
        $this->assertCount(1, $this->saved);

        return $this->saved[0];
    }

    private function settings(): UserSettingsContractInterface
    {
        $settings = $this->createStub(UserSettingsContractInterface::class);
        $settings->method('seedLanguage')->willReturnCallback(function (string $userId, string $language): void {
            $this->seeded[] = [$userId, $language, $this->active];
        });

        return $settings;
    }
}
