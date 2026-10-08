<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Interface\Controller;

use App\Auth\Application\Port\AuthenticatedUserIdentityInterface;
use App\Auth\Infrastructure\Security\SecurityUser;
use App\Library\Application\Command\CreateLibraryCommand;
use App\Library\Application\PathValidator;
use App\Library\Application\Port\LibraryReadScopeProviderInterface;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Interface\Controller\LibraryController;
use App\Library\Interface\Request\CreateLibraryRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Who may call the library endpoints, and what the creating admin is granted. */
final class LibraryControllerSecurityTest extends TestCase
{
    /** @param list<string> $expected */
    #[DataProvider('actions')]
    public function testOnlyAdminsChangeLibraries(string $method, array $expected): void
    {
        $grants = array_map(
            static fn (\ReflectionAttribute $attribute): mixed => $attribute->newInstance()->attribute,
            (new \ReflectionMethod(LibraryController::class, $method))->getAttributes(IsGranted::class),
        );

        self::assertSame($expected, $grants);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function actions(): array
    {
        return [
            'list' => ['index', []],
            'show' => ['show', []],
            'stats' => ['stats', []],
            'create' => ['store', ['ROLE_ADMIN']],
            'rename' => ['update', ['ROLE_ADMIN']],
            'delete' => ['destroy', ['ROLE_ADMIN']],
            'scan' => ['scan', ['ROLE_ADMIN']],
            'scan all' => ['scanAll', ['ROLE_ADMIN']],
            'validate path' => ['validatePath', ['ROLE_ADMIN']],
        ];
    }

    public function testCreationGrantsTheCreatingAdminAccess(): void
    {
        $adminId = Uuid::v7();
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message, [new HandledStamp(
                Library::create('Music', new LibrarySlug('music'), new LibraryPath('/media/music'), LibraryType::Music, FilesystemType::Local),
                'handler',
            )]);
        });
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser($adminId->toString(), 'admin@baander.app', 'hash', ['ROLE_ADMIN']));

        $response = $this->controller($bus, $security)->store(new CreateLibraryRequest(name: 'Music', path: '/media/music', type: 'music'));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(CreateLibraryCommand::class, $dispatched[0]);
        self::assertTrue($adminId->equals($dispatched[0]->grantTo ?? Uuid::v7()));
    }

    #[DataProvider('invalidCreators')]
    public function testCreationRejectsPrincipalsWithoutAUserIdBeforeDispatching(string $principal): void
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
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $response = $this->controller($bus, $security)->store(new CreateLibraryRequest(name: 'Music', path: '/media/music', type: 'music'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
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

    private function controller(MessageBusInterface $bus, Security $security): LibraryController
    {
        $controller = new LibraryController(
            commandBus: $bus,
            readScopes: $this->createStub(LibraryReadScopeProviderInterface::class),
            pathValidator: new PathValidator(),
            security: $security,
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        return $controller;
    }
}
