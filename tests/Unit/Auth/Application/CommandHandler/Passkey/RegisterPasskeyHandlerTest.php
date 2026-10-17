<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Application\CommandHandler\Passkey\RegisterPasskeyHandler;
use App\Auth\Domain\Event\Passkey\PasskeyRegistered;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

final class RegisterPasskeyHandlerTest extends TestCase
{
    private UserRepositoryInterface&Stub $userRepository;
    private PasskeyRepositoryInterface&Stub $passkeyRepository;
    private EventDispatcherInterface&Stub $eventDispatcher;
    private TransactionPortInterface&Stub $transaction;
    private RegisterPasskeyHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->passkeyRepository = $this->createStub(PasskeyRepositoryInterface::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->transaction = $this->createStub(TransactionPortInterface::class);
        $this->transaction->method('run')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $this->handler = $this->createRegisterPasskeyHandlerFixture();
    }

    private function createRegisterPasskeyHandlerFixture(): RegisterPasskeyHandler
    {
        return new RegisterPasskeyHandler(
            $this->userRepository,
            $this->passkeyRepository,
            $this->eventDispatcher,
            $this->transaction,
        );
    }

    public function testRegistersPasskey(): void
    {
        $insideTransaction = false;
        $this->transaction = $this->createMock(TransactionPortInterface::class);
        $this->transaction->expects($this->once())->method('run')->willReturnCallback(
            static function (callable $operation) use (&$insideTransaction): mixed {
                $insideTransaction = true;
                try {
                    return $operation();
                } finally {
                    $insideTransaction = false;
                }
            },
        );
        $this->passkeyRepository = $this->createMock(PasskeyRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);

        $user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $userId = $user->getId();
        $this->userRepository->expects($this->once())->method('findByUuid')->with($userId)->willReturn($user);
        $this->passkeyRepository->method('ofCredentialId')->willReturn(null);
        $this->passkeyRepository->expects($this->once())->method('save')->willReturnCallback(
            static function () use (&$insideTransaction): void {
                self::assertTrue($insideTransaction);
            },
        );
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects($this->once())->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$insideTransaction): object {
                self::assertTrue($insideTransaction);
                self::assertInstanceOf(PasskeyRegistered::class, $event);
                return $event;
            },
        );
        $this->handler = $this->createRegisterPasskeyHandlerFixture();

        $result = ($this->handler)(new RegisterPasskeyCommand(
            $userId->toString(),
            'My Key',
            'cred-id',
            ['userHandle' => $user->getPublicId()->toString()],
            0,
        ));

        $this->assertSame('My Key', $result->getName());
        $this->assertSame('cred-id', $result->getCredentialId());
        self::assertFalse($insideTransaction);
    }

    public function testThrowsOnUserNotFound(): void
    {
        $this->userRepository->method('findByUuid')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        ($this->handler)(new RegisterPasskeyCommand(
            Uuid::v4()->toString(),
            'Key',
            'cred-id',
            [],
            0,
        ));
    }

    public function testRejectsLegacyOrForeignHandleBeforePersisting(): void
    {
        $user = User::register(new Email('passkey@baander.app'), 'hash', 'Owner');
        $this->userRepository->method('findByUuid')->willReturn($user);
        $repository = $this->createMock(PasskeyRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $handler = new RegisterPasskeyHandler($this->userRepository, $repository, $this->eventDispatcher, $this->transaction);
        foreach ([$user->getId()->toString(), (new \App\Shared\Domain\Model\PublicId())->toString()] as $handle) {
            try {
                $handler(new RegisterPasskeyCommand($user->getId()->toString(), 'Key', 'credential', ['userHandle' => $handle], 0));
                self::fail('The credential handle must belong to the authenticated public user.');
            } catch (RuntimeException $error) {
                self::assertSame('Credential owner does not match its registered user handle.', $error->getMessage());
            }
        }
    }

    public function testThrowsOnDuplicateCredentialId(): void
    {
        $user = User::register(new Email('test@baander.app'), 'hashed', 'Alice');
        $this->userRepository->method('findByUuid')->willReturn($user);
        $passkey = Passkey::create(Uuid::v4(), 'Existing', 'cred-id', [], 0);
        $this->passkeyRepository->method('ofCredentialId')->willReturn($passkey);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        ($this->handler)(new RegisterPasskeyCommand(
            $user->getId()->toString(),
            'Key',
            'cred-id',
            ['userHandle' => $user->getPublicId()->toString()],
            0,
        ));
    }
}
