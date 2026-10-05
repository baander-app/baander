<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Interface\Controller;

use App\Media\Application\Port\MediaReadScopeProviderInterface;
use App\Media\Application\Port\StreamPortInterface;
use App\Media\Domain\Model\TrackStreamMetadata;
use App\Media\Interface\Controller\StreamController;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Domain\ValueObject\LibraryReadScope;
use App\Shared\Domain\ValueObject\MediaReadScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Authentication and library authorization for the PublicId track endpoint. */
final class StreamControllerSecurityTest extends TestCase
{
    private string $directory;
    private string $file;
    private PublicId $trackId;
    private Uuid $libraryId;
    private StreamPortInterface&Stub $streamService;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-stream-security-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->file = $this->directory . '/track.mp3';
        self::assertSame(10, file_put_contents($this->file, 'audio-data'));
        $this->trackId = new PublicId();
        $this->libraryId = new Uuid();
        $this->streamService = $this->createStub(StreamPortInterface::class);
        $this->streamService->method('getLibraryIdForTrack')->willReturn($this->libraryId);
        $this->streamService->method('getTrackMetadata')->willReturn($this->metadata());
        $this->streamService->method('resolveTrackPath')->willReturn($this->file);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        rmdir($this->directory);
    }

    public function testStreamByIdRequiresAuthenticationBeforeLookingUpTrack(): void
    {
        $this->streamService = $this->createMock(StreamPortInterface::class);
        $this->streamService->expects(self::never())->method('getLibraryIdForTrack');
        $this->streamService->expects(self::never())->method('getTrackMetadata');
        $this->streamService->expects(self::never())->method('resolveTrackPath');
        $controller = $this->controllerWithScope(MediaReadScope::none());

        $response = $controller->streamById($this->request());

        self::assertNotInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    #[DataProvider('deniedLibraryScopes')]
    public function testStreamByIdRejectsLibraryOutsideScope(LibraryReadScope $libraries): void
    {
        $this->streamService = $this->createMock(StreamPortInterface::class);
        $this->streamService
            ->expects(self::once())
            ->method('getLibraryIdForTrack')
            ->willReturn($this->libraryId);
        $this->streamService->expects(self::never())->method('getTrackMetadata');
        $this->streamService->expects(self::never())->method('resolveTrackPath');
        $controller = $this->controllerWithScope(MediaReadScope::authenticated(
            new Uuid(),
            $libraries,
        ));

        $response = $controller->streamById($this->request());

        self::assertNotInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /** @return iterable<string, array{LibraryReadScope}> */
    public static function deniedLibraryScopes(): iterable
    {
        yield 'no membership' => [LibraryReadScope::none()];
        yield 'different library' => [LibraryReadScope::restricted([new Uuid()])];
    }

    public function testStreamByIdAllowsLibraryMember(): void
    {
        $controller = $this->controllerWithScope(MediaReadScope::authenticated(
            new Uuid(),
            LibraryReadScope::restricted([$this->libraryId]),
        ));

        $this->assertStreamResponse($controller->streamById($this->request()));
    }

    public function testStreamByIdAllowsAuthenticatedAdministrator(): void
    {
        $controller = $this->controllerWithScope(MediaReadScope::authenticated(
            new Uuid(),
            LibraryReadScope::unrestricted(),
        ));

        $this->assertStreamResponse($controller->streamById($this->request()));
    }

    public function testStreamByIdReturnsNotFoundForMissingTrackAfterAuthentication(): void
    {
        $this->streamService = $this->createMock(StreamPortInterface::class);
        $this->streamService->expects(self::once())->method('getLibraryIdForTrack')->willReturn(null);
        $this->streamService->expects(self::never())->method('getTrackMetadata');
        $this->streamService->expects(self::never())->method('resolveTrackPath');
        $controller = $this->controllerWithScope(MediaReadScope::authenticated(
            new Uuid(),
            LibraryReadScope::none(),
        ));

        $response = $controller->streamById($this->request());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRangeRetriesUseFreshScopeAfterMembershipAndAuthenticationAreRevoked(): void
    {
        $actorId = new Uuid();
        $scopes = $this->createMock(MediaReadScopeProviderInterface::class);
        $scopes->expects(self::exactly(3))->method('current')->willReturnOnConsecutiveCalls(
            MediaReadScope::authenticated($actorId, LibraryReadScope::restricted([$this->libraryId])),
            MediaReadScope::authenticated($actorId, LibraryReadScope::none()),
            MediaReadScope::none(),
        );
        $this->streamService = $this->createMock(StreamPortInterface::class);
        $this->streamService
            ->expects(self::exactly(2))
            ->method('getLibraryIdForTrack')
            ->willReturn($this->libraryId);
        $this->streamService
            ->expects(self::once())
            ->method('getTrackMetadata')
            ->willReturn($this->metadata());
        $this->streamService
            ->expects(self::once())
            ->method('resolveTrackPath')
            ->willReturn($this->file);
        $controller = $this->createController($scopes);
        $request = $this->request();
        $request->headers->set('Range', 'bytes=0-3');

        $allowed = $controller->streamById($request);
        $allowed->prepare($request);
        self::assertSame(Response::HTTP_PARTIAL_CONTENT, $allowed->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $controller->streamById($request)->getStatusCode());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $controller->streamById($request)->getStatusCode());
    }

    #[DataProvider('invalidTrackIds')]
    public function testInvalidTrackIdIsRejectedBeforeScopeOrTrackLookup(?string $id): void
    {
        $scopes = $this->createMock(MediaReadScopeProviderInterface::class);
        $scopes->expects(self::never())->method('current');
        $this->streamService = $this->createMock(StreamPortInterface::class);
        $this->streamService->expects(self::never())->method('getLibraryIdForTrack');
        $controller = $this->createController($scopes);

        $response = $controller->streamById(new Request($id === null ? [] : ['id' => $id]));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidTrackIds(): iterable
    {
        yield 'missing' => [null];
        yield 'blank' => [' '];
        yield 'malformed' => ['invalid!'];
    }

    private function controllerWithScope(MediaReadScope $scope): StreamController
    {
        $scopes = $this->createStub(MediaReadScopeProviderInterface::class);
        $scopes->method('current')->willReturn($scope);

        return $this->createController($scopes);
    }

    private function createController(MediaReadScopeProviderInterface $scopes): StreamController
    {
        $controller = new StreamController(
            streamService: $this->streamService,
            scopes: $scopes,
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $controller->setTranslator($translator);

        return $controller;
    }

    private function request(): Request
    {
        return Request::create('https://api.baander.app/api/stream/track?id=' . $this->trackId->toString());
    }

    private function metadata(): TrackStreamMetadata
    {
        return new TrackStreamMetadata(
            publicId: $this->trackId->toString(),
            filename: 'track.mp3',
            filePath: $this->file,
            mimeType: 'audio/mpeg',
            size: 10,
            codec: null,
            bitrate: null,
            sampleRate: null,
            channels: null,
            length: null,
        );
    }

    private function assertStreamResponse(Response $response): void
    {
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }
}
