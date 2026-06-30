<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\Passkey\RegisterPasskeyCommand;
use App\Auth\Application\CommandHandler\Passkey\RegisterPasskeyHandler;
use App\Auth\Domain\Model\Passkey\Passkey;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\Passkey\PasskeyRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

final class RegisterPasskeyHandlerTest extends TestCase
{
    private UserRepositoryInterface&MockObject $userRepository;
    private PasskeyRepositoryInterface&MockObject $passkeyRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RegisterPasskeyHandler $handler;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->passkeyRepository = $this->createMock(PasskeyRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->handler = new RegisterPasskeyHandler($this->userRepository, $this->passkeyRepository, $this->eventDispatcher);
    }

    public function testRegistersPasskey(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $userId = $user->getId();
        $this->userRepository->method('findByUuid')->with($userId)->willReturn($user);
        $this->passkeyRepository->method('ofCredentialId')->willReturn(null);
        $this->passkeyRepository->expects($this->once())->method('save');

        $result = ($this->handler)(new RegisterPasskeyCommand(
            $userId->toString(),
            'My Key',
            'cred-id',
            ['data' => 'value'],
            0,
        ));

        $this->assertSame('My Key', $result->getName());
        $this->assertSame('cred-id', $result->getCredentialId());
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

    public function testThrowsOnDuplicateCredentialId(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $this->userRepository->method('findByUuid')->willReturn($user);
        $passkey = Passkey::create(Uuid::v4(), 'Existing', 'cred-id', [], 0);
        $this->passkeyRepository->method('ofCredentialId')->willReturn($passkey);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        ($this->handler)(new RegisterPasskeyCommand(
            $user->getId()->toString(),
            'Key',
            'cred-id',
            [],
            0,
        ));
    }
}
