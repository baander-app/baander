<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Application\CommandHandler\Passkey\RegisterPasskeyHandler;
use App\Auth\Application\Exception\CredentialsChangedException;
use App\Auth\Domain\Event\Passkey\PasskeyRegistered;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RegisterPasskeyHandlerTest extends TestCase
{
    use RecordsCredentialChanges;

    private UserRepositoryInterface&Stub $userRepository;
    private PasskeyRepositoryInterface&Stub $passkeyRepository;
    private User $user;
    /** @var list<string> the passkeys saved and the events recorded, each marked when it ran inside the transaction */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->userRepository->method('findByUuid')->willReturnCallback(fn (Uuid $id): ?User => $id->equals($this->user->getId()) ? $this->user : null);
        $this->passkeyRepository = $this->createStub(PasskeyRepositoryInterface::class);
        $this->passkeyRepository->method('save')->willReturnCallback(function (Passkey $passkey): void {
            $this->writes[] = 'save ' . $passkey->getName() . ($this->inTransaction ? ' in transaction' : '');
        });
    }

    /**
     * The passkey and its event are stored together. The session that completed the ceremony
     * stays signed in, the others end, and their connections close once it has committed.
     */
    public function testRegistersThePasskeyAndEndsEveryOtherSession(): void
    {
        $session = $this->signedInSession($this->user);

        $result = $this->handler()(new RegisterPasskeyCommand(
            $this->user->getId()->toString(),
            'My Key',
            'cred-id',
            ['userHandle' => $this->user->getPublicId()->toString()],
            0,
            $session,
        ));

        self::assertSame('My Key', $result->getName());
        self::assertSame('cred-id', $result->getCredentialId());
        self::assertSame(['save My Key in transaction', 'event cred-id in transaction'], $this->writes);
        self::assertSame([
            'end sessions keeping ' . $session,
            'commit',
            'close other-session-token',
        ], $this->credentialChanges);
    }

    /** Another change ended the requesting session while the ceremony ran: nothing is stored. */
    public function testACeremonyWhoseSessionWasEndedStoresNothing(): void
    {
        $session = $this->signedInSession($this->user);
        $this->requestingSessionCurrent = false;

        try {
            $this->handler()(new RegisterPasskeyCommand(
                $this->user->getId()->toString(),
                'My Key',
                'cred-id',
                ['userHandle' => $this->user->getPublicId()->toString()],
                0,
                $session,
            ));
            self::fail('A ceremony of an ended session must be refused.');
        } catch (CredentialsChangedException) {
        }

        self::assertSame([], $this->writes);
        self::assertSame(['rollback'], $this->credentialChanges);
    }

    public function testThrowsOnUserNotFound(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->handler()(new RegisterPasskeyCommand(
            Uuid::v4()->toString(),
            'Key',
            'cred-id',
            [],
            0,
        ));
    }

    public function testRejectsLegacyOrForeignHandleBeforePersisting(): void
    {
        $handler = $this->handler();
        foreach ([$this->user->getId()->toString(), (new PublicId())->toString()] as $handle) {
            try {
                $handler(new RegisterPasskeyCommand($this->user->getId()->toString(), 'Key', 'credential', ['userHandle' => $handle], 0));
                self::fail('The credential handle must belong to the authenticated public user.');
            } catch (RuntimeException $error) {
                self::assertSame('Credential owner does not match its registered user handle.', $error->getMessage());
            }
        }
        self::assertSame([], $this->writes);
    }

    public function testThrowsOnDuplicateCredentialId(): void
    {
        $passkey = Passkey::create(Uuid::v4(), 'Existing', 'cred-id', [], 0);
        $this->passkeyRepository->method('ofCredentialId')->willReturn($passkey);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->handler()(new RegisterPasskeyCommand(
            $this->user->getId()->toString(),
            'Key',
            'cred-id',
            ['userHandle' => $this->user->getPublicId()->toString()],
            0,
        ));
    }

    private function handler(): RegisterPasskeyHandler
    {
        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('dispatch')->willReturnCallback(function (object $event): object {
            self::assertInstanceOf(PasskeyRegistered::class, $event);
            $this->writes[] = 'event ' . $event->getCredentialId() . ($this->inTransaction ? ' in transaction' : '');

            return $event;
        });

        return new RegisterPasskeyHandler(
            $this->userRepository,
            $this->passkeyRepository,
            $events,
            $this->transaction(),
            $this->credentialChangeRevoker(),
        );
    }
}
