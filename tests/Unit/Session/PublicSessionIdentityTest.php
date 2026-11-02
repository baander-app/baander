<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Session\Domain\Model\Device\Device;
use App\Session\Domain\Model\ListeningSession\ListeningSession;
use App\Session\Domain\Model\Playback\PlaybackMedia;
use App\Session\Domain\Model\Playback\PlaybackState;
use App\Session\Domain\Repository\Device\DeviceRepositoryInterface;
use App\Session\Infrastructure\DeviceAdapter;
use App\Session\Interface\Controller\DeviceController;
use App\Session\Interface\Request\RenameDeviceRequest;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class PublicSessionIdentityTest extends TestCase
{
    public function testDeviceListingPreservesThePublicIdentityAndBrowserToken(): void
    {
        $owner = new Uuid();
        $token = new Uuid();
        $device = Device::create($owner, $token, 'Speaker');
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::once())->method('findByUserId')->with($owner)->willReturn([$device]);

        $listed = new DeviceAdapter($devices)->getDevices($owner)[0];

        self::assertArrayNotHasKey('id', $listed);
        self::assertSame($device->getPublicId()->toString(), $listed['publicId']);
        self::assertSame($token->toString(), $listed['deviceId']);
        self::assertSame('Speaker', $listed['name']);
        self::assertStringNotContainsString($device->getId()->toString(), json_encode($listed, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($owner->toString(), json_encode($listed, JSON_THROW_ON_ERROR));
    }

    public function testPlaybackRejectsInternalSongUuidWithoutReplacingRetainedState(): void
    {
        $songId = new PublicId();
        $session = ListeningSession::create(new Uuid(), [], 0, 0);
        $session->control('load', new PlaybackMedia('song', $songId), [$songId->toString()], 0, 8.5, PlaybackState::Paused);

        try {
            $session->control('queue', null, [new Uuid()->toString()], 0, null, null);
            self::fail('Queue entries must be public song identities.');
        } catch (\InvalidArgumentException) {
            self::assertSame([$songId->toString()], $session->getQueue());
            self::assertSame($songId, $session->media()?->publicId);
            self::assertSame(8.5, $session->getPosition());
            self::assertSame(PlaybackState::Paused, $session->desiredState());
            self::assertSame(1, $session->revision());
        }
    }

    public function testDeviceRenameRejectsInternalIdAndBrowserTokenBeforeLookup(): void
    {
        $owner = new Uuid();
        $device = Device::create($owner, new Uuid(), 'Speaker');
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::never())->method('findByUserAndPublicId');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($owner->toString(), 'owner@baander.app', 'hash'));
        $controller = new DeviceController($security, new DeviceAdapter($devices));
        foreach ([$device->getId(), $device->getDeviceId()] as $identifier) {
            self::assertSame(400, $controller->rename($identifier->toString(), new RenameDeviceRequest('Renamed'))->getStatusCode());
            self::assertSame(400, $controller->forget($identifier->toString())->getStatusCode());
        }
    }

    public function testForgettingADeviceDeletesOnlyItsOwnedRegistration(): void
    {
        $owner = new Uuid();
        $token = new Uuid();
        $device = Device::create($owner, $token, 'Speaker');
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::once())->method('findByUserAndPublicId')->with($owner, $device->getPublicId())->willReturn($device);
        $devices->expects(self::once())->method('remove')->with($device);

        new DeviceAdapter($devices)->forgetDevice($owner, $device->getPublicId());

        self::assertSame($token, $device->getDeviceId());
    }

    public function testForgettingAnUnknownOrForeignDeviceDoesNotDeleteAnything(): void
    {
        $owner = new Uuid();
        $publicId = new PublicId();
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::once())->method('findByUserAndPublicId')->with($owner, $publicId)->willReturn(null);
        $devices->expects(self::never())->method('remove');

        new DeviceAdapter($devices)->forgetDevice($owner, $publicId);
    }

    public function testRegistrationRetainsTheExistingIdentityAndNameWhenOnlyTouched(): void
    {
        $owner = new Uuid();
        $token = new Uuid();
        $device = Device::create($owner, $token, 'Speaker');
        $publicId = $device->getPublicId();
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::once())->method('findByUserAndDevice')->with($owner, $token)->willReturn($device);
        $devices->expects(self::once())->method('save')->with($device);

        new DeviceAdapter($devices)->registerDevice($owner, $token, '');

        self::assertSame($publicId, $device->getPublicId());
        self::assertSame('Speaker', $device->getName());
        self::assertNotNull($device->getLastSeenAt());
    }

    public function testRegistrationCreatesADeviceWithSeparatePublicIdentity(): void
    {
        $owner = new Uuid();
        $token = new Uuid();
        $devices = $this->createMock(DeviceRepositoryInterface::class);
        $devices->expects(self::once())->method('findByUserAndDevice')->with($owner, $token)->willReturn(null);
        $devices->expects(self::once())->method('save')->with(self::callback(
            static fn(Device $device): bool
            => $device->getUserId()->equals($owner)
            && $device->getDeviceId()->equals($token)
            && $device->getName() === 'Speaker'
            && $device->getPublicId()->toString() !== $token->toString(),
        ));

        new DeviceAdapter($devices)->registerDevice($owner, $token, 'Speaker');
    }
}
