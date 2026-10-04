<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Library\Application\PathValidator;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Library\Application\Query\LibraryStatsQueryPort;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Interface\Controller\LibraryController;
use App\Library\Interface\Request\UpdateLibraryRequest;
use App\Library\Interface\Request\CreateLibraryRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LibraryIdentityAuthorizationTest extends TestCase
{
    private function readScopes(): LibraryReadScopeProviderInterface
    {
        $provider = $this->createStub(LibraryReadScopeProviderInterface::class);
        $provider->method('current')->willReturn(LibraryReadScope::unrestricted());
        return $provider;
    }

    /** @param list<string> $roles */
    #[DataProvider('identities')]
    public function testUpdatePreservesAdminAndLibraryAccessAuthorization(array $roles, bool $hasAccess, bool $hasIdentity, int $expectedStatus): void
    {
        $id = new Uuid();
        $user = $hasIdentity
            ? $this->createStubForIntersectionOfInterfaces([UserInterface::class, AuthenticatedUserIdentityInterface::class])
            : $this->createStub(UserInterface::class);
        self::assertInstanceOf(UserInterface::class, $user);
        if ($hasIdentity) {
            self::assertInstanceOf(AuthenticatedUserIdentityInterface::class, $user);
            $user->method('getId')->willReturn($id->toString());
        }
        $user->method('getRoles')->willReturn($roles);
        $library = Library::create(
            name: 'Music',
            slug: LibrarySlug::fromName('music'),
            path: new LibraryPath('/media/music'),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
        );
        $service = $this->createMock(LibraryPortInterface::class);
        $service->method('findByUuid')->willReturn($library);
        $service->expects($expectedStatus === 200 ? $this->once() : $this->never())->method('save')->with($library);
        $access = $this->createMock(LibraryAccessPortInterface::class);
        if ($hasIdentity && !in_array('ROLE_ADMIN', $roles, true)) {
            $access->expects($this->once())->method('hasAccess')
                ->with(
                    $this->callback(static fn (Uuid $value): bool => $value->equals($id)),
                    $library->getId(),
                )
                ->willReturn($hasAccess);
        } else {
            $access->expects($this->never())->method('hasAccess');
        }
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'api', $roles));
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $controller = new LibraryController(
            libraryService: $service,
            statsQuery: $this->createStub(LibraryStatsQueryPort::class),
            pathValidator: new PathValidator(),
            commandBus: $this->createStub(MessageBusInterface::class),
            readScopes: $this->readScopes(),
            security: new Security($container),
            libraryAccess: $access,
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        $response = $controller->update(new UpdateLibraryRequest(name: 'Updated music'), $library->getId()->toString());

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame($expectedStatus === 200 ? 'Updated music' : 'Music', $library->getName());
    }

    /** @param list<string> $roles */
    #[DataProvider('scanRoles')]
    public function testScanAllStillRequiresTheAdminRole(array $roles, int $expectedStatus): void
    {
        $user = $this->createStubForIntersectionOfInterfaces([UserInterface::class, AuthenticatedUserIdentityInterface::class]);
        self::assertInstanceOf(UserInterface::class, $user);
        $user->method('getId')->willReturn((new Uuid())->toString());
        $user->method('getRoles')->willReturn($roles);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'api', $roles));
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $service = $this->createMock(LibraryPortInterface::class);
        $service->expects($expectedStatus === 202 ? $this->once() : $this->never())
            ->method('findVisible')->willReturn([]);
        $service->expects($this->never())->method('save');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $controller = new LibraryController(
            libraryService: $service,
            statsQuery: $this->createStub(LibraryStatsQueryPort::class),
            pathValidator: new PathValidator(),
            commandBus: $bus,
            readScopes: $this->readScopes(),
            security: new Security($container),
        );

        $response = $controller->scanAll();

        self::assertSame($expectedStatus, $response->getStatusCode());
    }

    /** @return array<string, array{list<string>, int}> */
    public static function scanRoles(): array
    {
        return [
            'ordinary user' => [['ROLE_USER'], 403],
            'administrator' => [['ROLE_ADMIN'], 202],
        ];
    }

    #[DataProvider('invalidCreators')]
    public function testCreationRejectsInvalidPrincipalsBeforeAnyLookupOrWrite(string $principal): void
    {
        $security = $this->createStub(Security::class);
        $user = null;
        if ($principal === 'unsupported') {
            $user = $this->createStub(UserInterface::class);
        } elseif ($principal === 'malformed identifier') {
            $user = $this->createStubForIntersectionOfInterfaces([UserInterface::class, AuthenticatedUserIdentityInterface::class]);
            $user->method('getId')->willReturn('invalid-user-uuid');
        }
        $security->method('getUser')->willReturn($user);
        $service = $this->createMock(LibraryPortInterface::class);
        $service->expects($this->never())->method('findBySlug');
        $service->expects($this->never())->method('save');
        $access = $this->createMock(LibraryAccessPortInterface::class);
        $access->expects($this->never())->method('grant');
        $controller = new LibraryController(
            libraryService: $service,
            statsQuery: $this->createStub(LibraryStatsQueryPort::class),
            pathValidator: new PathValidator(),
            commandBus: $this->createStub(MessageBusInterface::class),
            readScopes: $this->readScopes(),
            security: $security,
            libraryAccess: $access,
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);
        $payload = new CreateLibraryRequest(name: 'Music', path: '/media/music', type: 'music');

        $response = $controller->store($payload);

        self::assertSame(401, $response->getStatusCode());
    }

    /** @return array<string, array{string}> */
    public static function invalidCreators(): array
    {
        return [
            'no authenticated principal' => ['absent'],
            'no application identity contract' => ['unsupported'],
            'invalid UUID identity' => ['malformed identifier'],
        ];
    }

    /** @return array<string, array{list<string>, bool, bool, int}> */
    public static function identities(): array
    {
        return [
            'public admin identity' => [['ROLE_ADMIN'], false, true, 200],
            'public library owner identity' => [['ROLE_USER'], true, true, 200],
            'public identity without access' => [['ROLE_USER'], false, true, 403],
            'admin principal without application identity' => [['ROLE_ADMIN'], false, false, 403],
        ];
    }
}
