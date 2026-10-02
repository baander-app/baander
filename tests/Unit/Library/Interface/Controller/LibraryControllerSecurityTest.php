<?php

declare(strict_types=1);

namespace App\Tests\Unit\Library\Interface\Controller;

use App\Auth\Infrastructure\Security\SecurityUser;
use App\Library\Application\PathValidator;
use App\Library\Application\Port\LibraryAccessPortInterface;
use App\Library\Application\Port\LibraryPortInterface;
use App\Library\Application\Query\LibraryStatsQueryPort;
use App\Library\Domain\Model\Library;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use App\Library\Interface\Controller\LibraryController;
use App\Library\Interface\Request\CreateLibraryRequest;
use App\Library\Interface\Request\UpdateLibraryRequest;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\FilesystemType;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Security-focused tests for LibraryController mutating endpoints.
 *
 * The controller currently performs no ownership or admin authorization
 * checks before updating, deleting, or scanning libraries. These tests
 * assert the expected secure behaviour and fail against the current
 * production code.
 */
final class LibraryControllerSecurityTest extends TestCase
{
    private LibraryPortInterface $libraryService;
    private LibraryStatsQueryPort $statsQuery;
    private PathValidator $pathValidator;
    private MessageBusInterface $commandBus;
    private LibraryController $controller;

    protected function setUp(): void
    {
        $this->libraryService = $this->createStub(LibraryPortInterface::class);
        $this->statsQuery = $this->createStub(LibraryStatsQueryPort::class);
        $this->pathValidator = new PathValidator();
        $this->commandBus = $this->createStub(MessageBusInterface::class);

        $this->controller = $this->createLibraryControllerFixture();
    }

    private function createLibraryControllerFixture(): LibraryController
    {
        $fixture = new LibraryController(
            libraryService: $this->libraryService,
            statsQuery: $this->statsQuery,
            pathValidator: $this->pathValidator,
            commandBus: $this->commandBus,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $fixture->setTranslator($translator);
        return $fixture;
    }

    public function testStoreGrantsCreatorAccess(): void
    {
        $this->libraryService = $this->createMock(LibraryPortInterface::class);
        $this->controller = $this->createLibraryControllerFixture();

        $userId = Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $library = $this->createLibrary();

        $this->libraryService->method('findBySlug')->willReturn(null);
        $this->libraryService->expects($this->once())->method('save');

        $libraryAccess = $this->createMock(LibraryAccessPortInterface::class);
        $libraryAccess
            ->expects($this->once())
            ->method('grant')
            ->with($userId, self::isInstanceOf(Uuid::class));

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new SecurityUser(
            id: $userId->toString(),
            email: 'creator@example.com',
            password: 'password',
        ));

        $controller = new LibraryController(
            libraryService: $this->libraryService,
            statsQuery: $this->statsQuery,
            pathValidator: $this->pathValidator,
            commandBus: $this->commandBus,
            security: $security,
            libraryAccess: $libraryAccess,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        $payload = new CreateLibraryRequest(
            name: $library->getName(),
            path: $library->getPath()->toString(),
            type: $library->getType()->value,
            filesystemType: $library->getFilesystemType()->value,
        );

        $response = $controller->store($payload);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testStoreRequiresAuthentication(): void
    {
        $this->libraryService = $this->createMock(LibraryPortInterface::class);
        $this->controller = $this->createLibraryControllerFixture();

        $this->libraryService->method('findBySlug')->willReturn(null);
        $this->libraryService->expects($this->once())->method('save');

        $libraryAccess = $this->createMock(LibraryAccessPortInterface::class);
        $libraryAccess->expects($this->never())->method('grant');

        $controller = new LibraryController(
            libraryService: $this->libraryService,
            statsQuery: $this->statsQuery,
            pathValidator: $this->pathValidator,
            commandBus: $this->commandBus,
            security: null,
            libraryAccess: $libraryAccess,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        $library = $this->createLibrary();
        $payload = new CreateLibraryRequest(
            name: $library->getName(),
            path: $library->getPath()->toString(),
            type: $library->getType()->value,
            filesystemType: $library->getFilesystemType()->value,
        );

        $response = $controller->store($payload);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testUpdateRequiresOwnershipOrAdmin(): void
    {
        $this->libraryService = $this->createMock(LibraryPortInterface::class);
        $this->controller = $this->createLibraryControllerFixture();

        $library = $this->createLibrary();

        $this->libraryService->method('findByUuid')->willReturn($library);

        // Authorization should be checked before the aggregate is mutated or
        // persisted. Currently the controller saves without any checks.
        $this->libraryService->expects($this->never())->method('save');

        $payload = new UpdateLibraryRequest(name: 'Hijacked Library');
        $response = $this->controller->update($payload, $library->getId()->toString());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertNotSame('Hijacked Library', $library->getName());
    }

    public function testDestroyRequiresOwnershipOrAdmin(): void
    {
        $this->libraryService = $this->createMock(LibraryPortInterface::class);
        $this->controller = $this->createLibraryControllerFixture();

        $library = $this->createLibrary();

        $this->libraryService->method('findByUuid')->willReturn($library);

        // Authorization should be checked before the library is deleted.
        // Currently the controller deletes without any checks.
        $this->libraryService->expects($this->never())->method('delete');

        $response = $this->controller->destroy($library->getId()->toString());

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testScanRequiresOwnershipOrAdmin(): void
    {
        $this->commandBus = $this->createMock(MessageBusInterface::class);
        $this->libraryService = $this->createMock(LibraryPortInterface::class);
        $this->controller = $this->createLibraryControllerFixture();

        $library = $this->createLibrary();

        $this->libraryService->method('findByUuid')->willReturn($library);

        // Authorization should be checked before dispatching a scan command.
        // Currently the controller dispatches without any checks.
        $this->commandBus->expects($this->never())->method('dispatch');
        $this->libraryService->expects($this->never())->method('save');

        $request = new \Symfony\Component\HttpFoundation\Request();
        $response = $this->controller->scan($library->getId()->toString(), $request);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertNotSame('scanning', $library->getDiscoveryStatus());
    }

    private function createLibrary(): Library
    {
        return Library::create(
            name: 'Original Library',
            slug: LibrarySlug::fromName('original-library'),
            path: new LibraryPath('/media/music'),
            type: LibraryType::Music,
            filesystemType: FilesystemType::Local,
            sortOrder: 0,
        );
    }
}
