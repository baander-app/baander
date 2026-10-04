<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Repository\Device\DeviceRepositoryInterface;
use App\Session\Domain\Repository\ListeningSession\ListeningSessionRepositoryInterface;
use App\Session\Infrastructure\SessionAdapter;
use App\Session\Interface\Controller\DeviceController;
use App\Session\Interface\Request\RenameDeviceRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DeviceRenameFailureTest extends TestCase
{
    /** @return iterable<string, array{string, \Exception}> */
    public static function repositoryFailures(): iterable
    {
        yield 'lookup runtime failure' => ['lookup', new \RuntimeException('Device lookup failed.')];
        yield 'lookup argument failure' => ['lookup', new \InvalidArgumentException('Device lookup argument failed.')];
        yield 'save runtime failure' => ['save', new \RuntimeException('Device persistence failed.')];
        yield 'save argument failure' => ['save', new \InvalidArgumentException('Device persistence argument failed.')];
    }

    #[DataProvider('repositoryFailures')]
    public function testRepositoryFailureEscapesBothAdapterAndControllerUnchanged(string $stage, \Exception $failure): void
    {
        $userId = Uuid::generate();
        $deviceId = Uuid::generate();
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        if ($stage === 'lookup') {
            $devices->expects(self::once())->method('findByUserAndDevice')->with($userId, $deviceId)->willThrowException($failure);
            $devices->expects(self::never())->method('save');
        } else {
            $device = Device::create($userId, $deviceId, 'Original Name');
            $devices->expects(self::once())->method('findByUserAndDevice')->with($userId, $deviceId)->willReturn($device);
            $devices->expects(self::once())->method('save')->with($device)->willThrowException($failure);
        }
        $controller = $this->controller($userId, $devices);

        try {
            $controller->rename($deviceId->toString(), new RenameDeviceRequest('Updated Name'));
        } catch (\Throwable $actual) {
            self::assertSame($failure, $actual);

            return;
        }

        self::fail('Repository failures must escape instead of becoming a not-found response.');
    }

    public function testMissingDeviceReturnsNotFoundWithoutSaving(): void
    {
        $userId = Uuid::generate();
        $deviceId = Uuid::generate();
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::once())->method('findByUserAndDevice')->with($userId, $deviceId)->willReturn(null);
        $devices->expects(self::never())->method('save');
        $controller = $this->controller($userId, $devices);

        $response = $controller->rename($deviceId->toString(), new RenameDeviceRequest('Updated Name'));

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Device not found.', $body['error']['message']);
    }

    private function controller(Uuid $userId, DeviceRepositoryInterface $devices): DeviceController
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($userId->toString(), 'user@baander.app', 'hash'));
        $sessions = $this->createStub(ListeningSessionRepositoryInterface::class);
        $events = $this->createStub(EventDispatcherInterface::class);

        return new DeviceController($security, new SessionAdapter($sessions, $devices, $events));
    }
}
