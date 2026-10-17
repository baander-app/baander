<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\Application\Port\SessionUserPublicIdentityInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Repository\Device\DeviceRepositoryInterface;
use App\Session\Domain\Repository\ListeningSession\ListeningSessionRepositoryInterface;
use App\Session\Infrastructure\SessionAdapter;
use App\Session\Interface\Controller\DeviceController;
use App\Session\Interface\Request\RenameDeviceRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class PublicSessionIdentityTest extends TestCase
{
    public function testSessionProjectsPublicRelationshipsWhileDeviceListingPreservesBrowserToken(): void
    {
        $owner = new Uuid();
        $userPublicId = new PublicId();
        $token = new Uuid();
        $device = Device::create($owner, $token, 'Speaker');
        $session = ListeningSession::create($owner, [(new PublicId())->toString()], 0, 1.5);
        $session->claim($token);
        $sessions = $this->createStub(ListeningSessionRepositoryInterface::class);
        $sessions->method('findByUserId')->willReturn($session);
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::once())->method('findByUserAndDevice')->with($owner, $token)->willReturn($device);
        $devices->method('findByUserId')->willReturn([$device]);
        $users = $this->createMock(SessionUserPublicIdentityInterface::class);
        $users->expects(self::once())->method('publicIdByUserId')->with($owner)->willReturn($userPublicId);
        $adapter = new SessionAdapter($sessions, $devices, $this->createStub(EventDispatcherInterface::class), $users);

        $projection = $adapter->getSession($owner);
        self::assertSame($session->getPublicId()->toString(), $projection['publicId']);
        self::assertSame($userPublicId->toString(), $projection['userId']);
        self::assertSame($device->getPublicId()->toString(), $projection['activeDeviceId']);
        self::assertSame($session->getQueue(), $projection['queue']);
        $json = json_encode($projection, JSON_THROW_ON_ERROR);
        foreach ([$owner, $session->getId(), $device->getId(), $token] as $internalId) {
            self::assertStringNotContainsString($internalId->toString(), $json);
        }
        $listed = $adapter->getDevices($owner)[0];
        self::assertArrayNotHasKey('id', $listed);
        self::assertSame($device->getPublicId()->toString(), $listed['publicId']);
        self::assertSame($token->toString(), $listed['deviceId']);
    }

    public function testDeviceRenameRejectsInternalIdAndBrowserTokenBeforeLookup(): void
    {
        $owner = new Uuid();
        $device = Device::create($owner, new Uuid(), 'Speaker');
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::never())->method('findByUserAndPublicId');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'owner@baander.app', 'hash'));
        $controller = new DeviceController($security, new SessionAdapter(
            $this->createStub(ListeningSessionRepositoryInterface::class), $devices,
            $this->createStub(EventDispatcherInterface::class), $this->createStub(SessionUserPublicIdentityInterface::class),
        ));
        foreach ([$device->getId(), $device->getDeviceId()] as $identifier) {
            self::assertSame(400, $controller->rename($identifier->toString(), new RenameDeviceRequest('Renamed'))->getStatusCode());
            self::assertSame(400, $controller->forget($identifier->toString())->getStatusCode());
        }
    }

    public function testCreatingSessionRejectsInternalSongUuidBeforeRemovingExistingState(): void
    {
        $sessions = $this->createMock(ListeningSessionRepositoryInterface::class);
        $sessions->expects(self::never())->method('remove');
        $sessions->expects(self::never())->method('save');
        $adapter = new SessionAdapter($sessions, $this->createStub(DeviceRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class), $this->createStub(SessionUserPublicIdentityInterface::class));
        $this->expectException(\InvalidArgumentException::class);

        $adapter->createSession(new Uuid(), [(new Uuid())->toString()], 0, 0);
    }

    public function testForgetReleasesTheActiveDeviceWithoutChangingBrowserTokenSemantics(): void
    {
        $owner = new Uuid();
        $token = new Uuid();
        $device = Device::create($owner, $token, 'Speaker');
        $session = ListeningSession::create($owner, [], 0, 0);
        $session->claim($token);
        $sessions = $this->createMock(ListeningSessionRepositoryInterface::class);
        $sessions->expects(self::once())->method('findByUserId')->with($owner)->willReturn($session);
        $sessions->expects(self::once())->method('save')->with($session);
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::once())->method('findByUserAndPublicId')->with($owner, $device->getPublicId())->willReturn($device);
        $devices->expects(self::once())->method('remove')->with($device);
        $adapter = new SessionAdapter($sessions, $devices,
            $this->createStub(EventDispatcherInterface::class), $this->createStub(SessionUserPublicIdentityInterface::class));

        $adapter->forgetDevice($owner, $device->getPublicId());

        self::assertNull($session->getActiveDeviceId());
        self::assertSame($token, $device->getDeviceId());
    }

    public function testClaimRegistersBrowserTokenAndReturnsPersistedDevicePublicId(): void
    {
        $owner = new Uuid();
        $token = new Uuid();
        $session = ListeningSession::create($owner, [], 0, 0);
        $sessions = $this->createStub(ListeningSessionRepositoryInterface::class);
        $sessions->method('findByUserId')->willReturn($session);
        $registered = null;
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::exactly(2))->method('findByUserAndDevice')->with($owner, $token)->willReturnCallback(static function () use (&$registered): ?Device {
            return $registered;
        });
        $devices->expects(self::once())->method('save')->willReturnCallback(static function (Device $device) use (&$registered): void {
            $registered = $device;
        });
        $users = $this->createStub(SessionUserPublicIdentityInterface::class);
        $users->method('publicIdByUserId')->willReturn(new PublicId());
        $adapter = new SessionAdapter($sessions, $devices, $this->createStub(EventDispatcherInterface::class), $users);

        $projection = $adapter->claimSession($owner, $token);

        self::assertNotNull($registered);
        self::assertSame($registered->getPublicId()->toString(), $projection['activeDeviceId']);
        self::assertSame($token, $session->getActiveDeviceId());
    }
}
