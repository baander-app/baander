<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Application\CommandHandler;

use App\Auth\Application\Command\OAuth\ApproveDeviceCodeCommand;
use App\Auth\Application\CommandHandler\OAuth\ApproveDeviceCodeHandler;
use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\OAuth\DeviceCode;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\DeviceCodeRepositoryInterface;
use App\Auth\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Model\Email;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

final class ApproveDeviceCodeHandlerTest extends TestCase
{
    private DeviceCodeRepositoryInterface&MockObject $deviceCodeRepo;
    private UserRepositoryInterface&MockObject $userRepo;
    private EntityManagerInterface&MockObject $entityManager;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private ApproveDeviceCodeHandler $handler;

    protected function setUp(): void
    {
        $this->deviceCodeRepo = $this->createMock(DeviceCodeRepositoryInterface::class);
        $this->userRepo = $this->createMock(UserRepositoryInterface::class);

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->handler = new ApproveDeviceCodeHandler($this->deviceCodeRepo, $this->userRepo, $this->entityManager, $this->eventDispatcher);
    }

    private function createDeviceCode(): DeviceCode
    {
        return DeviceCode::create(
            Client::create('Test', []),
            'ABCD',
            '/verify',
            '/verify?code=ABCD',
        );
    }

    public function testApprovesDeviceCode(): void
    {
        $deviceCode = $this->createDeviceCode();
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');

        $this->deviceCodeRepo->method('findByUserCode')->with('ABCD')->willReturn($deviceCode);
        $this->userRepo->method('findByUuid')->willReturn($user);
        $this->deviceCodeRepo->expects($this->once())->method('save');

        $result = ($this->handler)(new ApproveDeviceCodeCommand('ABCD', $user->getId()));

        $this->assertSame('/verify?code=ABCD', $result);
    }

    public function testThrowsOnNotFound(): void
    {
        $this->deviceCodeRepo->method('findByUserCode')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No device code found');

        ($this->handler)(new ApproveDeviceCodeCommand('INVALID', Uuid::v4()));
    }

    public function testThrowsOnExpired(): void
    {
        $client = Client::create('Test', []);
        $deviceCode = DeviceCode::create($client, 'ABCD', '/v', ttl: new \DateInterval('PT0S'));
        $this->deviceCodeRepo->method('findByUserCode')->willReturn($deviceCode);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');

        ($this->handler)(new ApproveDeviceCodeCommand('ABCD', Uuid::v4()));
    }

    public function testThrowsOnDenied(): void
    {
        $deviceCode = $this->createDeviceCode();
        $deviceCode->deny();
        $this->deviceCodeRepo->method('findByUserCode')->willReturn($deviceCode);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('denied');

        ($this->handler)(new ApproveDeviceCodeCommand('ABCD', Uuid::v4()));
    }

    public function testThrowsOnAlreadyApproved(): void
    {
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');
        $deviceCode = $this->createDeviceCode();
        $deviceCode->approve($user);
        $this->deviceCodeRepo->method('findByUserCode')->willReturn($deviceCode);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been approved');

        ($this->handler)(new ApproveDeviceCodeCommand('ABCD', Uuid::v4()));
    }

    public function testThrowsOnUserNotFound(): void
    {
        $deviceCode = $this->createDeviceCode();
        $this->deviceCodeRepo->method('findByUserCode')->willReturn($deviceCode);
        $this->userRepo->method('findByUuid')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        ($this->handler)(new ApproveDeviceCodeCommand('ABCD', Uuid::v4()));
    }

    public function testReturnsVerificationUriWhenCompleteIsNull(): void
    {
        $deviceCode = DeviceCode::create(Client::create('Test', []), 'ABCD', '/verify');
        $user = User::register(new Email('test@example.com'), 'hashed', 'Alice');

        $this->deviceCodeRepo->method('findByUserCode')->willReturn($deviceCode);
        $this->userRepo->method('findByUuid')->willReturn($user);

        $result = ($this->handler)(new ApproveDeviceCodeCommand('ABCD', $user->getId()));

        $this->assertSame('/verify', $result);
    }
}
