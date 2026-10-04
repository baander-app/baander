<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Radio\Application\Port\CountrySubscriptionPortInterface;
use App\Radio\Application\Port\RadioSessionPortInterface;
use App\Radio\Application\Port\RadioSourcePortInterface;
use App\Radio\Application\Port\RadioStationPortInterface;
use App\Radio\Application\Port\StarredStationPortInterface;
use App\Radio\Interface\Controller\CountrySubscriptionController;
use App\Radio\Interface\Controller\RadioSessionController;
use App\Radio\Interface\Controller\RadioSourceController;
use App\Radio\Interface\Controller\RadioStationController;
use App\Radio\Interface\Controller\StarredStationController;
use App\Session\Application\Port\SessionPortInterface;
use App\Session\Interface\Controller\DeviceController;
use App\Session\Interface\Controller\SessionController;
use App\Shared\Domain\Model\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class IdentityPortControllerTest extends TestCase
{
    /** @return iterable<string, array{string, string, class-string, string, bool, bool}> */
    public static function controllers(): iterable
    {
        $controllers = [
            'subscriptions' => [CountrySubscriptionController::class, 'list', CountrySubscriptionPortInterface::class, 'listSubscriptions', true],
            'radio session' => [RadioSessionController::class, 'get', RadioSessionPortInterface::class, 'getSession', true],
            'sources' => [RadioSourceController::class, 'list', RadioSourcePortInterface::class, 'listSources', false],
            'countries' => [RadioStationController::class, 'countries', CountrySubscriptionPortInterface::class, 'listAvailableCountries', false],
            'starred' => [StarredStationController::class, 'list', StarredStationPortInterface::class, 'listStarred', true],
            'devices' => [DeviceController::class, 'list', SessionPortInterface::class, 'getDevices', true],
            'session' => [SessionController::class, 'get', SessionPortInterface::class, 'getSession', true],
        ];
        foreach ($controllers as $name => $arguments) {
            yield $name . ' identity' => [...$arguments, true];
            yield $name . ' unsupported principal' => [...$arguments, false];
        }
    }

    /** @param class-string $portClass */
    #[DataProvider('controllers')]
    public function testIdentityContractControlsAccess(
        string $controllerClass,
        string $action,
        string $portClass,
        string $portMethod,
        bool $scoped,
        bool $supported,
    ): void {
        $id = Uuid::generate();
        $principal = $supported
            ? $this->createStubForIntersectionOfInterfaces([AuthenticatedUserIdentityInterface::class, UserInterface::class])
            : $this->createStub(UserInterface::class);
        if ($supported) {
            $principal->method('getId')->willReturn($id->toString());
        }
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($principal);
        $port = $this->createMock($portClass);
        $expectation = $port->expects($supported ? self::once() : self::never())->method($portMethod);
        if ($supported && $scoped) {
            $expectation->with(self::callback(static fn (Uuid $userId): bool => $userId->equals($id)));
        }
        $expectation->willReturn([]);
        $bus = $this->createStub(MessageBusInterface::class);
        $controller = match (true) {
            $controllerClass === CountrySubscriptionController::class && $port instanceof CountrySubscriptionPortInterface => new CountrySubscriptionController($security, $port, $bus),
            $controllerClass === RadioSessionController::class && $port instanceof RadioSessionPortInterface => new RadioSessionController($security, $port, $bus),
            $controllerClass === RadioSourceController::class && $port instanceof RadioSourcePortInterface => new RadioSourceController($security, $port),
            $controllerClass === RadioStationController::class && $port instanceof CountrySubscriptionPortInterface => new RadioStationController($security, $this->createStub(RadioStationPortInterface::class), $port),
            $controllerClass === StarredStationController::class && $port instanceof StarredStationPortInterface => new StarredStationController($security, $port, $bus),
            $controllerClass === DeviceController::class && $port instanceof SessionPortInterface => new DeviceController($security, $port),
            $controllerClass === SessionController::class && $port instanceof SessionPortInterface => new SessionController($security, $port, $bus),
            default => throw new \LogicException('Unknown controller fixture.'),
        };

        self::assertSame($supported ? 200 : 401, $controller->{$action}()->getStatusCode());
    }
}
