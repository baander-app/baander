<?php

declare(strict_types=1);

namespace App\Tests\Unit\Radio\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Radio\Application\Command\StartRadioCommand;
use App\Radio\Application\Port\RadioSessionPortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Radio\Domain\Model\RadioStation\RadioStation;
use App\Radio\Interface\Controller\RadioSessionController;
use App\Radio\Interface\Request\StartRadioRequest;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RadioSessionPublicIdentityTest extends TestCase
{
    public function testPublicStationReferenceResolvesBeforeDispatch(): void
    {
        $station = RadioStation::create(new Uuid(), 'provider-station', 'Station', 'DK', []);
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::once())->method('findByPublicId')->with($station->getPublicId())->willReturn($station);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser((new Uuid())->toString(), 'listener@baander.app', ''));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (StartRadioCommand $command): bool => $command->getStationId()->equals($station->getId()),
        ))->willReturnCallback(static fn (object $command): Envelope => new Envelope($command));
        $controller = new RadioSessionController($security, $this->createStub(RadioSessionPortInterface::class), $bus, $stations);

        self::assertSame(200, $controller->start(new StartRadioRequest($station->getPublicId()->toString(), 'https://radio.baander.app/live'))->getStatusCode());
    }

    public function testInternalStationUuidIsRejectedWithoutLookupOrDispatch(): void
    {
        $stations = $this->createMock(RadioStationPortInterface::class);
        $stations->expects(self::never())->method('findByPublicId');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser((new Uuid())->toString(), 'listener@baander.app', ''));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = new RadioSessionController($security, $this->createStub(RadioSessionPortInterface::class), $bus, $stations);
        $this->expectException(NotFoundHttpException::class);

        $controller->start(new StartRadioRequest((new Uuid())->toString(), 'https://radio.baander.app/live'));
    }
}
